<?php

declare(strict_types=1);

namespace Tests\Unit\ExitLogs;

use App\Application\Audit\AuditActivitySanitizer;
use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Application\ExitLogs\Ports\ExitLogLockPort;
use App\Application\Stock\LocationValidator;
use App\Domain\ExitLogs\Exception\ExitLogLockDeniedException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use App\Domain\Mqtt\LockCommandPublisher;
use App\Modules\Audit\Services\AuditActivityService;
use App\Modules\ExitLogs\Services\ExitLogService;
use PDO;
use PDOStatement;
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
            'zone_id' => '00000000-0000-4000-8000-000000000001',
            'zone_resolved' => true,
            'zone_is_active' => true,
            'ambiente_resolved' => true,
            'ambiente_is_active' => true,
            'device_id' => 'DEVICE-UNIT-TEST',
        ];
    }

    /**
     * ExitLogService y AuditActivityService son final: se construyen con PDO stub
     * que hace que getDetail() devuelva null (sin auditoría en estos tests).
     *
     * @return array{0: ExitLogLockPort&MockObject, 1: LockCommandPublisher&MockObject, 2: OpenExitLogLockAction}
     */
    private function makeAction(): array
    {
        /** @var ExitLogLockPort&MockObject $port */
        $port = $this->createMock(ExitLogLockPort::class);
        /** @var LockCommandPublisher&MockObject $publisher */
        $publisher = $this->createMock(LockCommandPublisher::class);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $audit = new AuditActivityService($pdo, new AuditActivitySanitizer());
        $exitLogService = new ExitLogService($pdo, new LocationValidator($pdo), $audit);

        $action = new OpenExitLogLockAction(
            $port,
            $publisher,
            $this->createStub(LoggerInterface::class),
            $exitLogService,
            $audit,
        );

        return [$port, $publisher, $action];
    }

    public function testPublishesAndRecordsOnSuccess(): void
    {
        [$port, $publisher, $action] = $this->makeAction();

        $port->expects($this->once())->method('findContextForOpenLock')->with(self::CLINIC, '42', null)->willReturn($this->validContext());

        $publisher->expects($this->once())->method('publishOpenCommand')->with('DEVICE-UNIT-TEST');

        $port->expects($this->once())->method('recordLockCommandAttempt')->with(
            '42',
            self::CLINIC,
            'DEVICE-UNIT-TEST',
            'ambientes/DEVICE-UNIT-TEST/cmd',
            'open',
            'user-1',
            true,
            null
        );

        $result = $action->execute(self::CLINIC, '42', 'user-1');

        $this->assertSame('Lock open command sent successfully.', $result->message);
        $this->assertSame('42', $result->exitLogId);
        $this->assertSame('DEVICE-UNIT-TEST', $result->deviceId);
        $this->assertSame('ambientes/DEVICE-UNIT-TEST/cmd', $result->topic);
        $this->assertSame('open', $result->payload);
    }

    public function testThrowsWhenExitLogMissing(): void
    {
        [$port, $publisher, $action] = $this->makeAction();
        $port->method('findContextForOpenLock')->willReturn(null);
        $publisher->expects($this->never())->method('publishOpenCommand');

        $this->expectException(ExitLogNotFoundException::class);
        $action->execute(self::CLINIC, '99', 'user-1');
    }

    public function testThrowsWhenExitLogNotConfirmed(): void
    {
        [$port, $publisher, $action] = $this->makeAction();
        $port->method('findContextForOpenLock')->willReturn(array_merge($this->validContext(), ['status' => 'DRAFT']));
        $publisher->expects($this->never())->method('publishOpenCommand');

        $this->expectException(ExitLogLockDeniedException::class);
        $action->execute(self::CLINIC, '1', 'user-1');
    }

    public function testThrowsWhenZoneMissing(): void
    {
        [$port, $publisher, $action] = $this->makeAction();
        $port->method('findContextForOpenLock')->willReturn([
            'status' => 'CONFIRMED',
            'zone_id' => null,
            'zone_resolved' => false,
            'zone_is_active' => false,
            'ambiente_resolved' => false,
            'ambiente_is_active' => false,
            'device_id' => null,
        ]);
        $publisher->expects($this->never())->method('publishOpenCommand');

        $this->expectException(ExitLogLockDeniedException::class);
        $action->execute(self::CLINIC, '1', 'user-1');
    }

    public function testRecordsFailureAndRethrowsWhenMqttFails(): void
    {
        [$port, $publisher, $action] = $this->makeAction();

        $port->method('findContextForOpenLock')->willReturn($this->validContext());
        $publisher->method('publishOpenCommand')->willThrowException(new MqttPublishFailedException('broker down'));

        $port->expects($this->once())->method('recordLockCommandAttempt')->with(
            '7',
            self::CLINIC,
            'DEVICE-UNIT-TEST',
            'ambientes/DEVICE-UNIT-TEST/cmd',
            'open',
            'user-1',
            false,
            'broker down'
        );

        $this->expectException(MqttPublishFailedException::class);
        $action->execute(self::CLINIC, '7', 'user-1');
    }
}
