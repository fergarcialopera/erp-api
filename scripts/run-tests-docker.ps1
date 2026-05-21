# Ejecutar desde la raíz del repo en el host (no dentro del contenedor php).
# Flujo: infra → API en erp_test → migrar/seed → PHPUnit → restaurar erp.
# Uso: .\scripts\run-tests-docker.ps1
#      .\scripts\run-tests-docker.ps1 --filter LockersTreeEndpointTest
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Set-Location (Split-Path -Parent $PSScriptRoot)

$PhpUnitArgs = @($args)
if ($PhpUnitArgs.Count -gt 0 -and $PhpUnitArgs[0] -eq '--') {
    $PhpUnitArgs = @($PhpUnitArgs | Select-Object -Skip 1)
}

$ComposeBase = @('-f', 'docker-compose.yml')
$ComposeTest = @('-f', 'docker-compose.yml', '-f', 'docker-compose.test.yml')

function Invoke-Compose {
    param([string[]]$Extra)
    & docker compose @ComposeBase @Extra
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Invoke-ComposeTest {
    param([string[]]$Extra)
    & docker compose @ComposeTest @Extra
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Host '==> Comprobando servicios (postgres, redis, nginx)'
Invoke-Compose @('up', '-d', 'postgres', 'redis', 'nginx')

Write-Host '==> Activando API en modo test (DB erp_test)'
Invoke-ComposeTest @('up', '-d', '--force-recreate', 'php')

$exitCode = 0
try {
    Write-Host '==> Preparando base de datos de tests (migrate + seed)'
    Invoke-ComposeTest @('exec', '-T', 'php', 'php', 'bin/db.php', 'migrate')
    Invoke-ComposeTest @('exec', '-T', 'php', 'php', 'bin/db.php', 'seed')

    Write-Host '==> Ejecutando PHPUnit'
    $phpunitArgs = @('exec', '-T', 'php', 'vendor/bin/phpunit') + $PhpUnitArgs
    Invoke-ComposeTest $phpunitArgs
    $exitCode = $LASTEXITCODE
} finally {
    Write-Host '==> Restaurando API a BD de desarrollo (erp)'
    Invoke-Compose @('up', '-d', '--force-recreate', 'php')
}

exit $exitCode
