<?php

declare(strict_types=1);

namespace App\Application\Audit;

final readonly class AuditActor
{
    public function __construct(
        public string $userId,
        public ?string $clinicId,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function fromUser(array $user): self
    {
        $userId = (string) ($user['user_id'] ?? $user['id'] ?? '');
        $clinicId = trim((string) ($user['clinic_id'] ?? ''));

        return new self($userId, $clinicId !== '' ? $clinicId : null);
    }
}
