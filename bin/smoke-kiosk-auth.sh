#!/bin/sh
set -e
curl -sf http://nginx/api/v1/auth/clinics > /dev/null
RES=$(curl -sf -X POST http://nginx/api/v1/auth/clinic/login \
  -H 'Content-Type: application/json' \
  -d '{"clinic_id":"11111111-1111-1111-1111-111111111111","password":"clinic123"}')
echo "$RES" | grep -q clinic_access_token
TOKEN=$(echo "$RES" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["clinic_access_token"] ?? "";')
curl -sf -H "Authorization: Bearer $TOKEN" http://nginx/api/v1/auth/staff > /dev/null
curl -sf -X POST http://nginx/api/v1/auth/login/pin \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"44444444-4444-4444-4444-444444444444","pin":"1234"}' | grep -q access_token
echo "kiosk auth ok"
