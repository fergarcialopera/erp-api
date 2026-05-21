# Ejecutar desde la raíz del repo en el host (no dentro del contenedor php).
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$PhpUnitArgs
)

Set-Location (Split-Path -Parent $PSScriptRoot)

Write-Host "==> Activando API en modo test (DB erp_test)"
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --force-recreate php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

try {
    if ($PhpUnitArgs.Count -gt 0) {
        docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit @PhpUnitArgs
    } else {
        docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit
    }
    exit $LASTEXITCODE
} finally {
    Write-Host "==> Restaurando API a BD de desarrollo (erp)"
    docker compose -f docker-compose.yml up -d --force-recreate php
}
