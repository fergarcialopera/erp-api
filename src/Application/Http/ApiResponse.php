<?php

namespace App\Application\Http;

final class ApiResponse
{
    public static function success(Request $request, mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function error(Request $request, int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'instance' => $request->getUri(),
            'request_id' => $request->getAttribute('request_id'),
        ], $status);
    }
}
