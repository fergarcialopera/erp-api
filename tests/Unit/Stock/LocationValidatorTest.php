<?php

declare(strict_types=1);

namespace Tests\Unit\Stock;

use App\Application\Stock\LocationValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PDO;

final class LocationValidatorTest extends TestCase
{
    public function testLockerIdWithoutCompartmentIdThrows(): void
    {
        $pdo = $this->createMock(PDO::class);
        $validator = new LocationValidator($pdo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('locker_id requires compartment_id');

        $validator->parseOptionalLocation(['locker_id' => '40000000-0000-4000-8000-000000000001']);
    }

    public function testEmptyPayloadReturnsNull(): void
    {
        $pdo = $this->createMock(PDO::class);
        $validator = new LocationValidator($pdo);

        $this->assertNull($validator->parseOptionalLocation([]));
    }
}
