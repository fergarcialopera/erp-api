<?php

declare(strict_types=1);

namespace App\Modules\ExitLogs\Handlers;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\ExitLogs\Exception\ExitLogBusinessRuleException;
use App\Domain\ExitLogs\Exception\ExitLogNotFoundException;
use App\Modules\ExitLogs\Services\ExitLogService;
use App\Modules\ExitLogs\Support\ExitLogIdParser;
use Throwable;

final class CancelExitLogHandler
{
    public function __construct(private readonly ExitLogService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $user = (array) $request->getAttribute('user', []);
            $clinicId = (string) ($user['clinic_id'] ?? '');
            if ($clinicId === '') {
                return ApiResponse::error($request, 403, 'Forbidden', 'Missing clinic_id in user context');
            }

            $rawId = (string) $request->getAttribute('id', '');
            $exitLogId = ExitLogIdParser::parse($rawId);
            if ($exitLogId === null) {
                return ApiResponse::error($request, 400, 'Bad Request', 'Invalid exit log id');
            }

            return ApiResponse::success($request, $this->service->cancel($clinicId, $exitLogId));
        } catch (ExitLogNotFoundException $e) {
            return ApiResponse::error($request, 404, 'Not Found', $e->getMessage());
        } catch (ExitLogBusinessRuleException $e) {
            return ApiResponse::error($request, 422, 'Unprocessable Entity', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
