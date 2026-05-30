# IoT — Apertura de cerradura

El backend no abre cerraduras directamente.

---

## Flujo

1. Se invoca:

```
POST /exit-logs/{id}/open-lock
```

2. El backend:

- valida el `ExitLog`
- resuelve el `deviceId`
- publica en MQTT

### MQTT

- Topic: `lockers/{deviceId}/cmd`
- Payload: `open`

3. El ESP32:

- abre la cerradura
- gestiona el ciclo físico
- cierra cuando detecta puerta cerrada

---

## Configuración MQTT

Variables de entorno: ver [Configuración](../AGENTS.md#configuración).
