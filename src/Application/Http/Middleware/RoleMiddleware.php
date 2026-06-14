<?php

namespace App\Application\Http\Middleware;

use App\Application\Http\ApiResponse;
use App\Application\Http\Request;
use App\Application\Http\Response;
use App\Domain\Auth\Role;

final class RoleMiddleware implements MiddlewareInterface
{
    /** @var array<string, array<int, string>> */
    private array $rules;

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
        if ($role === '' || Role::weight($role) === 0) {
            return ApiResponse::error($request, 403, 'Forbidden', 'Insufficient role');
        }

        $allowedWeight = 0;
        foreach ($allowedRoles as $allowedRole) {
            $allowedWeight = max($allowedWeight, Role::weight($allowedRole));
        }

        if ($allowedWeight === 0 || Role::weight($role) < $allowedWeight) {
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
