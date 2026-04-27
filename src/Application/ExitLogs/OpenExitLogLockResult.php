<?php

declare(strict_types=1);

namespace App\Application\ExitLogs;

final class OpenExitLogLockResult
{
    public function __construct(
        public readonly string $message,
        public readonly string $exitLogId,
        public readonly string $deviceId,
        public readonly string $topic,
        public readonly string $payload
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toApiData(): array
    {
        return [
            'message' => $this->message,
            'exit_log_id' => $this->exitLogId,
            'device_id' => $this->deviceId,
            'topic' => $this->topic,
            'payload' => $this->payload,
        ];
    }
}
