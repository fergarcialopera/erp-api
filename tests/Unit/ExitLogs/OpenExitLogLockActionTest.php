<?php

declare(strict_types=1);

namespace Tests\Unit\ExitLogs;

use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Application\ExitLogs\Ports\ExitLogLockPort;
use App\Domain\ExitLogs\Exception\ExitLogLockDeniedException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use App\Domain\Mqtt\LockCommandPublisher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OpenExitLogLockActionTest extends TestCase
{
    private const CLINIC = '11111111-1111-1111-1111-111111111111';

    /** @return array<string, mixed> */
    private function validContext(): array
    {
        return [
            'status' => 'CONFIRMED',
            'compartment_public_id' => '01HZABCDEFGHIJKMNOPQRSTUV',
            'compartment_resolved' => true,
            'compartment_is_active' => true,
            'locker_resolved' => true,
            'locker_is_active' => true,
            'device_id' => 'DEVICE-UNIT-TEST',
        ];
    }

    public function testPublishesAndRecordsOnSuccess(): void
    {
        /** @var ExitLogLockPort&MockObject $port */
        $port = $this->createMock(ExitLogLockPort::class);
        /** @var LockCommandPublisher&MockObject $publisher */
        $publisher = $this->createMock(LockCommandPublisher::class);
        $logger = $this->createStub(LoggerInterface::class);

        $port->expects($this->once())->method('findContextForOpenLock')->with(self::CLINIC, '42')->willReturn($this->validContext());

        $publisher->expects($this->once())->method('publishOpenCommand')->with('DEVICE-UNIT-TEST');

        $port->expects($this->once())->method('recordLockCommandAttempt')->with(
            '42',
            self::CLINIC,
            'DEVICE-UNIT-TEST',
            'lockers/DEVICE-UNIT-TEST/cmd',
            'open',
            'user-1',
            true,
            null
        );

        $action = new OpenExitLogLockAction($port, $publisher, $logger);
        $result = $action->execute(self::CLINIC, '42', 'user-1');

        $this->assertSame('Lock open command sent successfully.', $result->message);
        $this->assertSame('42', $result->exitLogId);
        $this->assertSame('DEVICE-UNIT-TEST', $result->deviceId);
        $this->assertSame('lockers/DEVICE-UNIT-TEST/cmd', $result->topic);
        $this->assertSame('open', $result->payload);
    }

    public function testThrowsWhenExitLogMissing(): void
    {
        $port = $this->createMock(ExitLogLockPort::class);
        $port->method('findContextForOpenLock')->willReturn(null);
        $publisher = $this->createMock(LockCommandPublisher::class);
        $publisher->expects($this->never())->method('publishOpenCommand');

        $action = new OpenExitLogLockAction($port, $publisher, $this->createStub(LoggerInterface::class));

        $this->expectException(ExitLogNotFoundException::class);
        $action->execute(self::CLINIC, '99', 'user-1');
    }

    public function testThrowsWhenExitLogNotConfirmed(): void
    {
        $port = $this->createMock(ExitLogLockPort::class);
        $port->method('findContextForOpenLock')->willReturn(array_merge($this->validContext(), ['status' => 'DRAFT']));
        $publisher = $this->createMock(LockCommandPublisher::class);
        $publisher->expects($this->never())->method('publishOpenCommand');

        $action = new OpenExitLogLockAction($port, $publisher, $this->createStub(LoggerInterface::class));

        $this->expectException(ExitLogLockDeniedException::class);
        $action->execute(self::CLINIC, '1', 'user-1');
    }

    public function testThrowsWhenCompartmentMissing(): void
    {
        $port = $this->createMock(ExitLogLockPort::class);
        $port->method('findContextForOpenLock')->willReturn([
            'status' => 'CONFIRMED',
            'compartment_public_id' => null,
            'compartment_resolved' => false,
            'compartment_is_active' => false,
            'locker_resolved' => false,
            'locker_is_active' => false,
            'device_id' => null,
        ]);
        $publisher = $this->createMock(LockCommandPublisher::class);
        $publisher->expects($this->never())->method('publishOpenCommand');

        $action = new OpenExitLogLockAction($port, $publisher, $this->createStub(LoggerInterface::class));

        $this->expectException(ExitLogLockDeniedException::class);
        $action->execute(self::CLINIC, '1', 'user-1');
    }

    public function testRecordsFailureAndRethrowsWhenMqttFails(): void
    {
        /** @var ExitLogLockPort&MockObject $port */
        $port = $this->createMock(ExitLogLockPort::class);
        /** @var LockCommandPublisher&MockObject $publisher */
        $publisher = $this->createMock(LockCommandPublisher::class);

        $port->method('findContextForOpenLock')->willReturn($this->validContext());
        $publisher->method('publishOpenCommand')->willThrowException(new MqttPublishFailedException('broker down'));

        $port->expects($this->once())->method('recordLockCommandAttempt')->with(
            '7',
            self::CLINIC,
            'DEVICE-UNIT-TEST',
            'lockers/DEVICE-UNIT-TEST/cmd',
            'open',
            'user-1',
            false,
            'broker down'
        );

        $action = new OpenExitLogLockAction($port, $publisher, $this->createStub(LoggerInterface::class));

        $this->expectException(MqttPublishFailedException::class);
        $action->execute(self::CLINIC, '7', 'user-1');
    }
}
