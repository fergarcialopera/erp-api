<?php

declare(strict_types=1);

namespace App\Domain\Mqtt;

interface LockCommandPublisher
{
    /**
     * Publishes payload "open" to topic ambientes/{deviceId}/cmd.
     *
     * @throws \App\Domain\Mqtt\Exception\MqttPublishFailedException
     */
    public function publishOpenCommand(string $deviceId): void;
}
