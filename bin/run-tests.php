<?php

declare(strict_types=1);

/**
 * Punto de entrada único para la suite en Docker:
 * activa erp_test, migra/seed, ejecuta PHPUnit y restaura erp.
 *
 * Uso (desde la raíz del repo):
 *   php bin/run-tests.php
 *   php bin/run-tests.php --filter LockersTreeEndpointTest
 *   composer test:docker
 *   composer test:docker -- --filter Auth
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
while ($args !== [] && $args[0] === '--') {
    array_shift($args);
}

$script = PHP_OS_FAMILY === 'Windows'
    ? $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'run-tests-docker.ps1'
    : $root . '/scripts/run-tests-docker.sh';

if (!is_file($script)) {
    fwrite(STDERR, "Script no encontrado: {$script}\n");
    exit(1);
}

if (PHP_OS_FAMILY === 'Windows') {
    $escaped = array_map(static fn (string $a): string => escapeshellarg($a), $args);
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($script);
    if ($escaped !== []) {
        // Evita que PowerShell interprete --filter u otras flags de PHPUnit.
        $command .= ' -- ' . implode(' ', $escaped);
    }
} else {
    $escaped = array_map('escapeshellarg', $args);
    $command = 'sh ' . escapeshellarg($script) . ($escaped !== [] ? ' ' . implode(' ', $escaped) : '');
}

passthru($command, $exitCode);
exit((int) $exitCode);
