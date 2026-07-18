<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Http\Request;

final readonly class AuditRequestContext
{
    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $requestId,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $ip = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $userAgent = $request->getHeader('user-agent');
        $requestId = (string) $request->getAttribute('request_id', '');

        return new self(
            $ip !== '' ? $ip : null,
            $userAgent !== null && $userAgent !== '' ? $userAgent : null,
            $requestId !== '' ? $requestId : null,
        );
    }
}
