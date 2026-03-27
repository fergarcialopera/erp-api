<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\Request;
use App\Application\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
