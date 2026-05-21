<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Handlers;

use App\Application\ExitLogs\ExitLogUserScope;
use App\Application\ExitLogs\OpenExitLogLockAction;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\ExitLogs\Exception\ExitLogLockDeniedException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Domain\Mqtt\Exception\MqttPublishFailedException;
use App\Modules\ExitLogs\Support\ExitLogIdParser;
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
            $exitLogId = ExitLogIdParser::parse($rawId);
            if ($exitLogId === null) {
                return ApiResponse::error($request, 400, 'Bad Request', 'Invalid exit log id');
            }

            $scope = ExitLogUserScope::restrictToCreatorForStaff($user);
            $result = $this->action->execute($clinicId, $exitLogId, $requestedBy, $scope);

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
}
