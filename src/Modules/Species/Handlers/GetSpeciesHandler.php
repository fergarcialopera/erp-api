<?php

declare(strict_types=1);

namespace App\Modules\Species\Handlers;

use App\Application\Auth\AccessDeniedException;
use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Modules\Species\Services\SpeciesService;
use Throwable;

final class GetSpeciesHandler
{
    public function __construct(private readonly SpeciesService $service)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $id = (string) $request->getAttribute('species_id', '');
            if ($id === '') {
                return ApiResponse::error($request, 404, 'Not Found', 'Species not found');
            }

            $species = $this->service->get($id);
            if ($species === null) {
                return ApiResponse::error($request, 404, 'Not Found', 'Species not found');
            }

            return ApiResponse::success($request, $species);
        } catch (AccessDeniedException $e) {
            return ApiResponse::error($request, 403, 'Forbidden', $e->getMessage());
        } catch (Throwable $throwable) {
            return ApiResponse::error($request, 500, 'Internal Server Error', $throwable->getMessage());
        }
    }
}
