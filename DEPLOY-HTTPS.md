# Despliegue a producción: HTTPS (rama actual)

Plan de subida a producción de la rama que activa TLS en `erp-nginx` con certificados
Let's Encrypt emitidos por **certbot en el host** (sin contenedores nuevos).

**Requisitos ya cumplidos** (verificados antes de redactar este plan):

- El dominio tiene un **registro DNS A** apuntando a la IP del VPS (no una redirección web).
- VPS Ubuntu/Debian con acceso root por SSH.
- El deploy lo ejecuta GitHub Actions al mergear a `main` (`scripts/deploy-remote.sh`).

**Orden crítico:** preparar el VPS (fases 1–2) **antes** de mergear la rama (fase 3).
Si se mergea antes, el deploy fallará con un mensaje explicativo y el stack anterior
seguirá funcionando en HTTP; no se rompe nada, pero el deploy no pasa.

**Downtime esperado:** 2–5 minutos durante la fase 2 (la emisión del certificado
necesita el puerto 80 libre).

En todos los comandos, sustituir `midominio.com` por el dominio real.

---

## Fase 0 — Comprobaciones previas (desde cualquier máquina)

```bash
# El dominio debe resolver a la IP del VPS:
nslookup midominio.com

# La web actual responde por HTTP:
curl -I http://midominio.com/
```

Si `nslookup` no devuelve la IP del VPS, detente aquí y revisa el DNS.

## Fase 1 — Preparar el VPS (SSH, sin downtime)

```bash
# 1. Abrir el puerto 443 (si usas ufw; revisa también el firewall del panel del proveedor)
ufw allow 443/tcp
ufw status

# 2. Instalar certbot y crear el webroot para renovaciones
apt update && apt install -y certbot
mkdir -p /var/www/certbot
```

> Algunos proveedores (IONOS, Hetzner, OVH...) tienen un firewall adicional en su
> panel web, independiente de ufw. Verifica que el 443 (HTTPS) esté permitido ahí.

## Fase 2 — Emitir el certificado (SSH, ~2–5 min de downtime)

```bash
cd /root/erp-api

# 1. Parar el stack para liberar el puerto 80
make down

# 2. Emitir el certificado (modo standalone)
certbot certonly --standalone -d midominio.com

# 3. Configurar .env.production (añadir/actualizar estas líneas):
#      SERVER_NAME=midominio.com
#      APP_PUBLIC_URL=https://midominio.com
#      FRONTEND_URL=https://midominio.com
nano .env.production

# 4. Volver a levantar el stack (versión antigua, aún en HTTP; restaura el servicio
#    mientras se hace el merge)
make up
```

Verifica que el certificado existe: `ls /etc/letsencrypt/live/midominio.com/`
(debe contener `fullchain.pem` y `privkey.pem`).

## Fase 3 — Merge y deploy (GitHub)

1. Mergear la rama en `main` (PR o merge directo).
2. GitHub Actions ejecuta `scripts/deploy-remote.sh`, que ahora:
   - valida que `.env.production` tiene `SERVER_NAME` y que el certificado existe;
   - reconstruye `erp-nginx` con TLS en `:443` y redirección `301` desde `:80`;
   - hace health check del API por `http://127.0.0.1/up` y del SPA por `https://127.0.0.1/`.
3. Revisar que el workflow termina en verde.

## Fase 4 — Renovación automática (SSH, una sola vez)

La emisión inicial quedó en modo standalone, que exigiría parar Nginx en cada
renovación. Cambiarla a modo webroot (sin cortes):

```bash
certbot certonly --webroot -w /var/www/certbot -d midominio.com --force-renewal

# Hook para que nginx recargue el certificado tras cada renovación
printf '#!/bin/sh\ndocker exec erp-nginx nginx -s reload\n' \
  > /etc/letsencrypt/renewal-hooks/deploy/reload-erp-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-erp-nginx.sh

# Simulacro de renovación (no emite nada, solo comprueba que funcionará)
certbot renew --dry-run
```

El timer de systemd del paquete certbot (`systemctl list-timers | grep certbot`)
renueva automáticamente a partir de aquí. No hay nada más que mantener.

## Fase 5 — Verificación final

```bash
# Desde el VPS:
curl http://127.0.0.1/up            # health check por :80 (200)
curl -kI https://127.0.0.1/         # SPA por :443 (200)

# Desde fuera:
curl -I http://midominio.com/       # 301 → https
curl -I https://midominio.com/      # 200, sin avisos de certificado
```

En el navegador: entrar en `https://midominio.com`, hacer login y comprobar que
el SPA llama al API sin errores de contenido mixto (consola del navegador limpia).
Probar también la recuperación de contraseña: el enlace del email debe llegar
con `https://` (lo genera el backend desde `FRONTEND_URL`).

## Frontend

El SPA llama al API con rutas relativas (`/api/v1/...`), así que en principio no
requiere cambios. Si el build de `erp-frontend` tuviera alguna URL absoluta
`http://` (variable de entorno del build, assets), regenerarlo en el VPS:

```bash
cd /root/erp-frontend && npm ci && npm run build
```

## Rollback

Si algo falla tras el merge:

1. `git revert` del merge en `main` → GitHub Actions redespliega la versión HTTP anterior.
2. El certificado emitido no estorba: queda en `/etc/letsencrypt` sin usarse.
3. **Precaución HSTS:** si algún navegador ya visitó la web por HTTPS, recordará
   usar HTTPS durante un año para ese dominio. Hacer rollback a HTTP dejaría a esos
   navegadores sin acceso. Por eso conviene verificar la fase 5 cuanto antes y,
   si hay problemas, arreglar hacia delante en lugar de volver a HTTP.

## Notas

- **Solo apex, sin `www`**: el certificado y el `server_name` cubren `midominio.com`.
  Para cubrir también `www.midominio.com` habría que añadir `-d www.midominio.com`
  a los comandos de certbot y ampliar `SERVER_NAME` en la plantilla de Nginx.
- Los detalles de la configuración (nginx, compose, variables) están en el README,
  sección «10) Producción (VPS) y HTTPS».
