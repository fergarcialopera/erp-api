<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$request = \App\Application\Http\Request::fromGlobals();
$response = $app['dispatcher']->dispatch($request);
$response->send();
