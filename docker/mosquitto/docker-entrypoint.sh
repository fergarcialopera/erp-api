#!/bin/sh
set -eu

PASSWD_FILE="/mosquitto/data/passwd"

if [ -z "${MQTT_USERNAME:-}" ] || [ -z "${MQTT_PASSWORD:-}" ]; then
  echo "mosquitto: defina MQTT_USERNAME y MQTT_PASSWORD (p. ej. en .env en la raiz del proyecto para Docker Compose)." >&2
  exit 1
fi

if [ ! -f "$PASSWD_FILE" ]; then
  mosquitto_passwd -c -b "$PASSWD_FILE" "$MQTT_USERNAME" "$MQTT_PASSWORD"
  chown mosquitto:mosquitto "$PASSWD_FILE"
  chmod 600 "$PASSWD_FILE"
fi

exec su mosquitto -s /bin/sh -c "exec /usr/sbin/mosquitto -c /mosquitto/config/mosquitto.conf"
