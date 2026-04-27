<?php

declare(strict_types=1);

namespace App\Infrastructure\Mqtt;

use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use App\Domain\Mqtt\LockCommandPublisher;
use App\Infrastructure\Config\Config;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class PhpMqttLockCommandPublisher implements LockCommandPublisher
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publishOpenCommand(string $deviceId): void
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new MqttPublishFailedException('deviceId must not be empty.');
        }

        $host = trim((string) $this->config->get('mqtt.host', ''));
        if ($host === '') {
            throw new MqttPublishFailedException('MQTT is not configured (missing MQTT_HOST).');
        }

        $port = (int) $this->config->get('mqtt.port', 1883);
        $clientId = trim((string) $this->config->get('mqtt.client_id', 'erp-backend'));
        if ($clientId === '') {
            $clientId = 'erp-backend';
        }

        $username = $this->config->get('mqtt.username');
        $password = $this->config->get('mqtt.password');
        $usernameStr = is_string($username) && $username !== '' ? $username : null;
        $passwordStr = is_string($password) && $password !== '' ? $password : null;

        $topic = 'lockers/' . $deviceId . '/cmd';
        $payload = 'open';

        $settings = new ConnectionSettings();
        if ($usernameStr !== null) {
            $settings = $settings->setUsername($usernameStr);
        }
        if ($passwordStr !== null) {
            $settings = $settings->setPassword($passwordStr);
        }
        $settings = $settings->setConnectTimeout(5)->setSocketTimeout(5);

        $client = new MqttClient($host, $port, $clientId, MqttClient::MQTT_3_1_1, null, $this->logger);

        try {
            $client->connect($settings, true);
            $client->publish($topic, $payload, 0, false);
            $client->disconnect();
        } catch (ConnectingToBrokerFailedException | DataTransferException | MqttClientException $e) {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
            throw new MqttPublishFailedException($e->getMessage(), 0, $e);
        }
    }
}
