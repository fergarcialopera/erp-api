<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\Request;
use App\Application\Http\Response;
use Psr\Log\LoggerInterface;

final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $context = [
            'request_id' => $request->getAttribute('request_id'),
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
        ];
        $this->logger->info('HTTP request', $context);

        $response = $next($request);
        $this->logger->info('HTTP response', $context);

        return $response;
    }
}
