<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Handlers;

use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\ExitLogs\Exception\ExitLogLockDeniedException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use Throwable;

final class OpenExitLogLockHandler
{
    public function __construct(private readonly OpenExitLogLockAction $action)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            $requestedBy = (string) ($user['user_id'] ?? '');
            if ($clinicId === '' || $requestedBy === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Invalid user context');
            }

            $rawId = (string) $request->getAttribute('id', '');
            $exitLogId = $this->parseExitLogId($rawId);
            if ($exitLogId === null) {
                return ApiResponse::error($request, 400, 'Bad Request', 'Invalid exit log id');
            }

            $result = $this->action->execute($clinicId, $exitLogId, $requestedBy);

            return ApiResponse::success($request, $result->toApiData());
        } catch (ExitLogNotFoundException $e) {
            return ApiResponse::error($request, 404, 'Not Found', $e->getMessage());
        } catch (ExitLogLockDeniedException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (MqttPublishFailedException $e) {
            return ApiResponse::error($request, 502, 'Bad Gateway', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }

    private function parseExitLogId(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $raw) === 1) {
            return $raw;
        }

        $lower = strtolower($raw);
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $lower
        ) === 1) {
            return $lower;
        }

        return null;
    }
}
