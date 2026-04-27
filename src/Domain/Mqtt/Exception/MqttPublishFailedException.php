<?php

declare(strict_types=1);

namespace App\Domain\Mqtt\Exception;

use RuntimeException;

final class MqttPublishFailedException extends RuntimeException
{
}
