<?php

namespace App\Infrastructure\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(string $name = 'app'): LoggerInterface
    {
        $logger = new Logger($name);
        $logger->pushHandler(new StreamHandler('php://stdout', Level::Info));
        return $logger;
    }
}
