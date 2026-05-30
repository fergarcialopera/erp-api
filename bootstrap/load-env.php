<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    $dotenvClass = '\Dotenv\Dotenv';
    if (class_exists($dotenvClass)) {
        $dotenvClass::createImmutable(dirname(__DIR__))->safeLoad();
    }
}
