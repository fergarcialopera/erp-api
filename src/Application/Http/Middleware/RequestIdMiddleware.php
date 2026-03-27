<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\Request;
use App\Application\Http\Response;
use Symfony\Component\Uid\Uuid;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $request = $request->withAttribute('request_id', Uuid::v4()->toRfc4122());
        return $next($request);
    }
}
