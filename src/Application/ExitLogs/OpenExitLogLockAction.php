<?php

declare(strict_types=1);

namespace App\Application\ExitLogs;

use App\Application\ExitLogs\Ports\ExitLogLockPort;
use App\Domain\ExitLogs\ExitLogLockPolicy;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use App\Domain\Mqtt\LockCommandPublisher;
use Psr\Log\LoggerInterface;

final class OpenExitLogLockAction
{
    private const PAYLOAD = 'open';

    public function __construct(
        private readonly ExitLogLockPort $exitLogLockPort,
        private readonly LockCommandPublisher $lockCommandPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(
        string $clinicId,
        string $exitLogId,
        string $requestedBy,
        ?string $createdByUserId = null
    ): OpenExitLogLockResult {
        $row = $this->exitLogLockPort->findContextForOpenLock($clinicId, $exitLogId, $createdByUserId);
        if ($row === null) {
            throw new ExitLogNotFoundException('Exit log not found.');
        }

        ExitLogLockPolicy::assertCanOpenLock($row);

        $deviceId = trim((string) $row['device_id']);
        $topic = 'lockers/' . $deviceId . '/cmd';

        $this->logger->info('exit_log.lock_open.requested', [
            'exit_log_id' => $exitLogId,
            'clinic_id' => $clinicId,
            'device_id' => $deviceId,
            'topic' => $topic,
            'requested_by' => $requestedBy,
        ]);

        try {
            $this->lockCommandPublisher->publishOpenCommand($deviceId);
        } catch (MqttPublishFailedException $e) {
            $this->exitLogLockPort->recordLockCommandAttempt(
                $exitLogId,
                $clinicId,
                $deviceId,
                $topic,
                self::PAYLOAD,
                $requestedBy,
                false,
                $e->getMessage()
            );
            $this->logger->error('exit_log.lock_open.mqtt_failed', [
                'exit_log_id' => $exitLogId,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->exitLogLockPort->recordLockCommandAttempt(
            $exitLogId,
            $clinicId,
            $deviceId,
            $topic,
            self::PAYLOAD,
            $requestedBy,
            true,
            null
        );

        $this->logger->info('exit_log.lock_open.sent', [
            'exit_log_id' => $exitLogId,
            'device_id' => $deviceId,
            'topic' => $topic,
        ]);

        return new OpenExitLogLockResult(
            'Lock open command sent successfully.',
            $exitLogId,
            $deviceId,
            $topic,
            self::PAYLOAD
        );
    }
}
