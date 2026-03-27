<?php

return [
    'name' => $_ENV['APP_NAME'] ?? 'ERP Clinic Stock',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
];
