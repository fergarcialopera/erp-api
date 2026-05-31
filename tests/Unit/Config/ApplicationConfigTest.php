<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Infrastructure\Config\ApplicationConfig;
use PHPUnit\Framework\TestCase;

final class ApplicationConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('DB_DATABASE');
        putenv('REDIS_HOST');
        putenv('MQTT_HOST');
        unset($_ENV['APP_ENV'], $_ENV['DB_DATABASE'], $_ENV['REDIS_HOST'], $_ENV['MQTT_HOST']);
        parent::tearDown();
    }

    public function testLoadBuildsInfrastructureConfigFromWhitelist(): void
    {
        $_ENV['APP_ENV'] = 'local';
        $_ENV['DB_DATABASE'] = 'erp';
        $_ENV['REDIS_HOST'] = 'redis-test';
        $_ENV['MQTT_HOST'] = 'broker';
        putenv('APP_ENV=local');
        putenv('DB_DATABASE=erp');
        putenv('REDIS_HOST=redis-test');
        putenv('MQTT_HOST=broker');

        $config = ApplicationConfig::load();
        $infra = $config->infrastructure();

        $this->assertSame('redis-test', $infra->get('redis.host'));
        $this->assertSame('broker', $infra->get('mqtt.host'));
        $this->assertSame('erp', $config->database()['database']);
        $this->assertSame(1800, $config->authUserTtl());
    }
}
