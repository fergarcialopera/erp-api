<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;

final class RoleMiddleware implements MiddlewareInterface
{
    /** @var array<string, array<int, string>> */
    private array $rules;

    /** @var array<string, int> */
    private array $weights = [
        'STAFF' => 1,
        'TECHNICIAN' => 2,
        'ADMIN' => 3,
    ];

    /**
     * @param array<string, array<int, string>> $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function process(Request $request, callable $next): Response
    {
        $ruleKey = sprintf('%s %s', $request->getMethod(), $request->getUri());
        $allowedRoles = $this->rules[$ruleKey] ?? $this->matchRegexRule($request);
        if ($allowedRoles === null) {
            return $next($request);
        }

        $user = (array) $request->getAttribute('user', []);
        $role = strtoupper((string) ($user['role'] ?? ''));
        if ($role === '' || !isset($this->weights[$role])) {
            return ApiResponse::error($request, 403, 'Forbidden', 'Insufficient role');
        }

        $allowedWeight = 0;
        foreach ($allowedRoles as $allowedRole) {
            $normalized = strtoupper($allowedRole);
            $allowedWeight = max($allowedWeight, $this->weights[$normalized] ?? 0);
        }

        if ($allowedWeight === 0 || $this->weights[$role] < $allowedWeight) {
            return ApiResponse::error($request, 403, 'Forbidden', 'Insufficient role');
        }

        return $next($request);
    }

    /**
     * @return array<int, string>|null
     */
    private function matchRegexRule(Request $request): ?array
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        foreach ($this->rules as $key => $roles) {
            if (!str_starts_with($key, 're:')) {
                continue;
            }
            $pattern = substr($key, 3);
            if (@preg_match($pattern, '') === false) {
                continue;
            }

            if (preg_match($pattern, sprintf('%s %s', $method, $uri)) === 1) {
                return $roles;
            }
        }

        return null;
    }
}
