# ERP API - Guia de inicio rapido

Backend API para gestion de inventario clinico (PHP + Nginx + PostgreSQL + Redis) con Docker.

## 1) Requisitos previos

Instala estas herramientas antes de empezar:

- **Git**
- **Docker Desktop** (Windows/macOS) o Docker Engine + Docker Compose (Linux)

Recomendado:

- Cliente API como Postman, Insomnia o Bruno

Notas:

- En Windows, usa Docker Desktop con WSL2 habilitado.
- Asegurate de que Docker este abierto antes de ejecutar comandos.

## 2) Clonar el proyecto

```bash
git clone https://github.com/fergarcialopera/erp-api.git
cd erp-api
```

Si ya tienes el codigo en otra carpeta, entra directamente en la raiz del proyecto.

## 3) Configurar variables de entorno

Crea tu `.env` a partir de `.env.example`.

En Linux/macOS:

```bash
cp .env.example .env
```

En PowerShell (Windows):

```powershell
Copy-Item .env.example .env
```

Valores por defecto del proyecto:

- APP URL: `http://localhost:8080`
- PostgreSQL: `erp/erp`
- Redis: `redis:6379`
- Mosquitto: credenciales en `MQTT_USERNAME` / `MQTT_PASSWORD` (ver seccion 13); el API no las usa aun

## 4) Levantar contenedores

```bash
docker compose up -d --build
```

Servicios que se levantan:

- `nginx` (API expuesta en el puerto `8080`)
- `php` (FPM)
- `postgres` (puerto `5432`)
- `redis` (puerto `6379`)
- `mosquitto` (broker MQTT, puerto `1883`; solo infraestructura, el backend no lo usa aun)

## 5) Ejecutar migraciones y seeders

Este proyecto usa un sistema simple de SQL versionado:

- Migraciones: `database/migrations`
- Seeders: `database/seeders`
- Script CLI: `bin/db.php`

Ejecuta:

```bash
docker compose exec php composer db:migrate
docker compose exec php composer db:seed
docker compose exec php composer db:status
```

Comandos disponibles:

- `composer db:migrate` -> aplica migraciones pendientes
- `composer db:seed` -> aplica seeders pendientes
- `composer db:status` -> muestra estado

## 6) Verificar que todo funciona

Health endpoint:

```bash
curl http://localhost:8080/up
```

Debe responder `200` con un JSON similar a:

```json
{"data":{"status":"up"},"meta":{}}
```

## 7) Login de prueba

Endpoint:

- `POST http://localhost:8080/api/v1/auth/login`

Payload:

```json
{
  "email": "admin@clinic.local",
  "password": "admin123"
}
```

## 8) Documentacion API

- OpenAPI YAML: `http://localhost:8080/docs`
- Swagger UI: `http://localhost:8080/docs/ui`

## 9) Ejecutar tests

```bash
docker compose exec php vendor/bin/phpunit
```

## 10) Flujo diario recomendado

1. Levantar servicios:

   ```bash
   docker compose up -d
   ```

2. Comprobar estado DB:

   ```bash
   docker compose exec php composer db:status
   ```

3. Al traer cambios nuevos:

   ```bash
   docker compose exec php composer db:migrate
   docker compose exec php composer db:seed
   ```

4. Ejecutar tests:

   ```bash
   docker compose exec php vendor/bin/phpunit
   ```

## 11) Problemas comunes (y solucion)

### Error 500 en login

Primero confirma que estas usando el puerto correcto del backend:

- Correcto: `http://localhost:8080`
- Incorrecto en este proyecto: `http://localhost:8081` (suele ser frontend u otro servicio)

### Docker no arranca contenedores

- Verifica que Docker Desktop este iniciado.
- Reintenta:

  ```bash
  docker compose down
  docker compose up -d --build
  ```

## 12) Estructura del proyecto (resumen)

- `src/Application` -> HTTP core, dispatcher, middlewares
- `src/Modules` -> modulos funcionales (Auth, Inventory, EntryLogs, ExitLogs, etc.)
- `src/Infrastructure` -> DB, Redis, router, logger, OpenAPI controller
- `database/migrations` -> SQL de esquema versionado
- `database/seeders` -> datos iniciales
- `docs/openapi.yaml` -> contrato API
- `tests/Integration` -> tests HTTP/integracion

## 13) MQTT (Mosquitto) — desarrollo local con autenticacion

**Eclipse Mosquitto** esta integrado como servicio Docker para pruebas y para conectar mas adelante el backend y dispositivos (por ejemplo un ESP32) con **usuario y contrasena**. **El codigo PHP del ERP no usa MQTT todavia**; las variables `MQTT_*` solo preparan la convencion para cuando se implemente.

### Que hace la infraestructura

- `allow_anonymous false` y contrasenas en un fichero **`passwd`** generado **solo dentro del volumen** `mosquitto_data` (no se sube al repositorio).
- En el **primer arranque**, un script de entrada ejecuta `mosquitto_passwd` con los valores de `MQTT_USERNAME` y `MQTT_PASSWORD` que lee Docker Compose desde tu `.env`.
- Sin TLS en esta fase (solo red local / laboratorio).

### Archivos creados o relevantes

| Archivo | Rol |
|--------|-----|
| `docker/mosquitto/mosquitto.conf` | Broker: puerto `1883`, sin anonimos, `password_file` en el volumen de datos. |
| `docker/mosquitto/docker-entrypoint.sh` | Crea `/mosquitto/data/passwd` si no existe y arranca Mosquitto como usuario `mosquitto`. |
| `.gitattributes` | Fuerza finales de linea LF en `*.sh` de Mosquitto (evita fallos en Windows). |

### Archivos modificados

| Archivo | Motivo |
|--------|--------|
| `docker-compose.yml` | Servicio `mosquitto`: variables `MQTT_USERNAME` / `MQTT_PASSWORD`, entrypoint, montaje del script; usuario root solo para generar `passwd` y hacer `su` al usuario del broker. |
| `.env.example` | Plantilla `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME`, `MQTT_PASSWORD` para Compose y futura aplicacion (copiar a `.env` y personalizar). |

### Variables de entorno (obligatorio para arrancar Mosquitto)

En la raiz del proyecto, tu `.env` (creado desde `.env.example`) debe incluir al menos:

- `MQTT_USERNAME`
- `MQTT_PASSWORD`

Docker Compose las inyecta en el contenedor `mosquitto`. El servicio `php` **no** recibe estas variables: el comportamiento del API no cambia.

Valores orientativos para **otro contenedor** o **futuro Laravel**: `MQTT_HOST=mosquitto`, `MQTT_PORT=1883`. Para un ESP32 en la LAN: `MQTT_HOST` sera la IP del PC o del servidor Docker.

### Arrancar Mosquitto con el resto del entorno

```bash
docker compose up -d --build
```

Solo Mosquitto:

```bash
docker compose up -d mosquitto
```

Si faltan `MQTT_USERNAME` o `MQTT_PASSWORD`, el contenedor saldra con error y un mensaje en logs.

### Comprobacion manual

1. **Puerto 1883** (PowerShell):

   ```powershell
   Test-NetConnection -ComputerName localhost -Port 1883
   ```

2. **Logs:**

   ```bash
   docker compose logs -f mosquitto
   ```

3. **Publicar / suscribir con credenciales** (sustituye usuario y contrasena por los de tu `.env`):

   Terminal A:

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -u erp_mqtt -P 'tu_password' -t demo/test -C 1
   ```

   Terminal B:

   ```bash
   docker compose exec mosquitto mosquitto_pub -h 127.0.0.1 -p 1883 -u erp_mqtt -P 'tu_password' -t demo/test -m "hola"
   ```

   En A debe mostrarse `hola`.

4. **Probar que la autenticacion es obligatoria** (sin usuario/contrasena deberia fallar o no recibir nada de forma fiable):

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -t demo/test -C 1 -W 3
   ```

   Con **contrasena incorrecta**:

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -u erp_mqtt -P incorrecta -t demo/test -C 1 -W 3
   ```

   En ambos casos no deberia completarse una suscripcion valida como con las credenciales buenas (mensajes de error en la salida o timeout `-W`).

### Regenerar el fichero `passwd`

Si cambias `MQTT_PASSWORD` (o `MQTT_USERNAME`) en `.env`, el fichero antiguo sigue en el volumen hasta que lo borres.

```bash
docker compose exec mosquitto sh -c "rm -f /mosquitto/data/passwd"
docker compose restart mosquitto
```

En el siguiente arranque se vuelve a crear `passwd` con los valores actuales del entorno.

### Persistencia y reversibilidad

- Configuracion y script: en el repo, montados solo lectura.
- `passwd` y datos persistentes del broker: volumen `mosquitto_data`; logs: volumen `mosquitto_log`.

Para deshacer la integracion: quita el servicio y volumenes en `docker-compose.yml`, elimina `docker/mosquitto/` si quieres, y opcionalmente `docker volume rm` sobre los volumenes Mosquitto.

### Nota de seguridad

Usuario/contrasena sin TLS solo es aceptable en **desarrollo local**. En produccion usa TLS y politicas de red adecuadas.

**Opcional (contenedor en la misma red Compose):** red por defecto con carpeta del proyecto `erp`: `erp_erp_net`; cliente efimero con `-h mosquitto -p 1883` y las mismas credenciales.

## 14) Referencia del repositorio

- GitHub: [fergarcialopera/erp-api](https://github.com/fergarcialopera/erp-api.git)

