<?php

declare(strict_types=1);

/** @var array{app: array{name: string, env: string, debug: bool, url: string}} $config */
$config = require __DIR__ . '/application.php';

return $config['app'];
