<?php

declare(strict_types=1);

namespace App\Application\Audit;

final class AuditActivitySanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'pin',
        'pin_hash',
        'token',
        'recovery_token',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                if ($value !== null && $value !== '') {
                    $sanitized[$key] = '[redacted]';
                }
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $sanitized[$key] = $this->sanitize($nested);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_ends_with($normalized, '_' . $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
