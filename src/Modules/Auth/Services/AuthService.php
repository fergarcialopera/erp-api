<?php

namespace App\Modules\Auth\Services;

use App\Infrastructure\Auth\TokenService;
use App\Modules\Auth\DTOs\LoginDTO;
use App\Modules\Auth\Mappers\AuthMapper;
use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TokenService $tokenService,
        private readonly AuthMapper $mapper
    ) {
    }

    public function login(LoginDTO $dto): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, clinic_id, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $dto->email]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active']) {
            throw new RuntimeException('Invalid credentials');
        }

        if (!password_verify($dto->password, (string) $user['password_hash'])) {
            throw new RuntimeException('Invalid credentials');
        }

        $payload = $this->mapper->toTokenPayload($user);
        $token = $this->tokenService->issueToken($payload);

        $response = $this->mapper->toLoginResponse($token, $payload);
        $response['expires_in'] = $this->tokenService->getTtlSeconds();
        return $response;
    }

    public function validateToken(string $token): ?array
    {
        return $this->tokenService->validateToken($token);
    }

    public function logout(string $token): void
    {
        $this->tokenService->invalidateToken($token);
    }
}
