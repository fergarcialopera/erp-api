<?php

namespace App\Application\Http;

final class JsonResponse extends Response
{
    public function __construct(array $data, int $statusCode = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        parent::__construct($statusCode, $headers, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
