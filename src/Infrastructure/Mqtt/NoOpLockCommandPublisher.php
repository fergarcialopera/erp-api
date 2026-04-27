<?php

declare(strict_types=1);

namespace App\Infrastructure\Mqtt;

use App\Domain\Mqtt\LockCommandPublisher;

/**
 * Used when MQTT is disabled or not configured (e.g. tests / local without broker).
 */
final class NoOpLockCommandPublisher implements LockCommandPublisher
{
    public function publishOpenCommand(string $deviceId): void
    {
        // Intentionally no-op
    }
}
