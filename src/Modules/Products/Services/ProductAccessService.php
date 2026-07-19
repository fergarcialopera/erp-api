<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use PDO;

/**
 * Comprueba si un usuario (rol operativo) puede retirar un producto del locker.
 * Independiente de users.role (autorización API).
 */
final class ProductAccessService
{
    public const DENIED_MESSAGE = 'Tu rol no tiene permiso para retirar productos de este tipo de dispensación.';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{id?: string, operational_role_id?: string|null}|string $user User row/array or user id
     * @param array{id?: string, dispensing_type_id?: string|null}|string $product Product row/array or product id
     * @return array{allowed: bool, reason: string|null}
     */
    public function canUserAccessProduct(array|string $user, array|string $product): array
    {
        $operationalRoleId = $this->resolveOperationalRoleId($user);
        $dispensingTypeId = $this->resolveDispensingTypeId($product);

        if ($dispensingTypeId === null || $dispensingTypeId === '') {
            return ['allowed' => false, 'reason' => self::DENIED_MESSAGE];
        }

        if ($operationalRoleId === null || $operationalRoleId === '') {
            return ['allowed' => false, 'reason' => self::DENIED_MESSAGE];
        }

        $rulesCount = $this->countRulesForDispensingType($dispensingTypeId);
        if ($rulesCount === 0) {
            // Sin reglas definidas: denegar por defecto (operaciones de locker).
            return ['allowed' => false, 'reason' => self::DENIED_MESSAGE];
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dispensing_type_roles
             WHERE dispensing_type_id::text = :dispensing_type_id
               AND role_id::text = :role_id
             LIMIT 1'
        );
        $stmt->execute([
            'dispensing_type_id' => $dispensingTypeId,
            'role_id' => $operationalRoleId,
        ]);

        if ($stmt->fetch()) {
            return ['allowed' => true, 'reason' => null];
        }

        return ['allowed' => false, 'reason' => self::DENIED_MESSAGE];
    }

    /**
     * @param array{id?: string, operational_role_id?: string|null}|string $user
     */
    private function resolveOperationalRoleId(array|string $user): ?string
    {
        if (is_array($user)) {
            if (array_key_exists('operational_role_id', $user) && $user['operational_role_id'] !== null && $user['operational_role_id'] !== '') {
                return (string) $user['operational_role_id'];
            }
            $userId = (string) ($user['id'] ?? $user['user_id'] ?? '');
            if ($userId === '') {
                return null;
            }
        } else {
            $userId = $user;
        }

        $stmt = $this->pdo->prepare(
            'SELECT operational_role_id::text AS operational_role_id
             FROM users WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['operational_role_id'] === null) {
            return null;
        }

        return (string) $row['operational_role_id'];
    }

    /**
     * @param array{id?: string, dispensing_type_id?: string|null}|string $product
     */
    private function resolveDispensingTypeId(array|string $product): ?string
    {
        if (is_array($product)) {
            if (array_key_exists('dispensing_type_id', $product) && $product['dispensing_type_id'] !== null && $product['dispensing_type_id'] !== '') {
                return (string) $product['dispensing_type_id'];
            }
            $productId = (string) ($product['id'] ?? '');
            if ($productId === '') {
                return null;
            }
        } else {
            $productId = $product;
        }

        $stmt = $this->pdo->prepare(
            'SELECT dispensing_type_id::text AS dispensing_type_id
             FROM products WHERE id::text = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['dispensing_type_id'] === null) {
            return null;
        }

        return (string) $row['dispensing_type_id'];
    }

    private function countRulesForDispensingType(string $dispensingTypeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)::int AS cnt FROM dispensing_type_roles
             WHERE dispensing_type_id::text = :dispensing_type_id'
        );
        $stmt->execute(['dispensing_type_id' => $dispensingTypeId]);
        $row = $stmt->fetch();

        return is_array($row) ? (int) $row['cnt'] : 0;
    }
}
