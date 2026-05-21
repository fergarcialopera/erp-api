#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = getenv('TEST_BASE_URL') ?: 'http://nginx';

function http(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $hdrs = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode($raw, true)];
}

$clinics = http('GET', $base . '/api/v1/auth/clinics');
if ($clinics['status'] !== 200) {
    fwrite(STDERR, "clinics failed\n");
    exit(1);
}

$login = http('POST', $base . '/api/v1/auth/clinic/login', [
    'clinic_id' => '11111111-1111-1111-1111-111111111111',
    'password' => 'clinic123',
]);
if ($login['status'] !== 200) {
    fwrite(STDERR, json_encode($login, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$token = $login['json']['data']['clinic_access_token'] ?? '';
$pin = http('POST', $base . '/api/v1/auth/login/pin', [
    'user_id' => '44444444-4444-4444-4444-444444444444',
    'pin' => '1234',
], ['Authorization' => 'Bearer ' . $token]);

if ($pin['status'] !== 200) {
    fwrite(STDERR, json_encode($pin, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo "kiosk auth ok\n";
