<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Application\Support\PinValidator;
use App\Infrastructure\Mail\SmtpMailer;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class RecoveryService
{
    public const TYPE_CLINIC_PASSWORD = 'clinic_password';
    public const TYPE_USER_PASSWORD = 'user_password';
    public const TYPE_USER_PIN = 'user_pin';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SmtpMailer $mailer,
        private readonly string $frontendBaseUrl,
        private readonly int $ttlMinutes = 60
    ) {
    }

    public function requestClinicPasswordByAdminEmail(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id AS user_id, u.email, c.id AS clinic_id, c.name AS clinic_name
             FROM users u
             INNER JOIN clinics c ON c.id = u.clinic_id
             WHERE u.email = :email AND u.role = \'ADMIN\' AND u.is_active = TRUE AND c.visible = TRUE
             LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        if (!$row) {
            return;
        }

        $rawToken = $this->issueToken(self::TYPE_CLINIC_PASSWORD, (string) $row['clinic_id'], null);
        $this->sendEmail(
            (string) $row['email'],
            'Recuperar contraseña de clínica',
            sprintf(
                "Hola,\n\nSolicitaste recuperar la contraseña de acceso de la clínica \"%s\".\n\n%s\n\nEl enlace caduca en %d minutos.\n",
                (string) $row['clinic_name'],
                $this->buildLink(self::TYPE_CLINIC_PASSWORD, $rawToken),
                $this->ttlMinutes
            )
        );
    }

    public function requestUserRecovery(string $email, string $type): void
    {
        if (!in_array($type, [self::TYPE_USER_PASSWORD, self::TYPE_USER_PIN], true)) {
            throw new RuntimeException('Invalid recovery type');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, name FROM users WHERE email = :email AND is_active = TRUE LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        if (!$row) {
            return;
        }

        $subject = $type === self::TYPE_USER_PIN ? 'Recuperar PIN de acceso' : 'Recuperar contraseña de usuario';
        $label = $type === self::TYPE_USER_PIN ? 'PIN' : 'contraseña';

        $rawToken = $this->issueToken($type, (string) $row['id'], null);
        $this->sendEmail(
            (string) $row['email'],
            $subject,
            sprintf(
                "Hola %s,\n\nSolicitaste recuperar tu %s de acceso.\n\n%s\n\nEl enlace caduca en %d minutos.\n",
                (string) ($row['name'] ?? $row['email']),
                $label,
                $this->buildLink($type, $rawToken),
                $this->ttlMinutes
            )
        );
    }

    public function sendUserRecoveryByAdmin(string $userId, string $clinicId, string $type, string $adminUserId): void
    {
        if (!in_array($type, [self::TYPE_USER_PASSWORD, self::TYPE_USER_PIN], true)) {
            throw new RuntimeException('Invalid recovery type');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, name FROM users
             WHERE id::text = :id AND clinic_id::text = :clinic_id AND is_active = TRUE LIMIT 1'
        );
        $stmt->execute(['id' => $userId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('User not found');
        }

        $subject = $type === self::TYPE_USER_PIN ? 'PIN restablecido por administrador' : 'Contraseña restablecida por administrador';
        $label = $type === self::TYPE_USER_PIN ? 'PIN' : 'contraseña';

        $rawToken = $this->issueToken($type, (string) $row['id'], $adminUserId);
        $this->sendEmail(
            (string) $row['email'],
            $subject,
            sprintf(
                "Hola %s,\n\nUn administrador ha solicitado restablecer tu %s.\n\n%s\n\nEl enlace caduca en %d minutos.\n",
                (string) ($row['name'] ?? $row['email']),
                $label,
                $this->buildLink($type, $rawToken),
                $this->ttlMinutes
            )
        );
    }

    public function confirm(string $rawToken, string $type, ?string $newPassword, ?string $newPin): void
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->pdo->prepare(
            'SELECT id, type, subject_id, expires_at, used_at
             FROM recovery_tokens
             WHERE token_hash = :hash AND type = :type
             LIMIT 1'
        );
        $stmt->execute(['hash' => $hash, 'type' => $type]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('Invalid or expired recovery token');
        }

        match ($type) {
            self::TYPE_CLINIC_PASSWORD => $this->applyClinicPassword((string) $row['subject_id'], (string) $newPassword),
            self::TYPE_USER_PASSWORD => $this->applyUserPassword((string) $row['subject_id'], (string) $newPassword),
            self::TYPE_USER_PIN => $this->applyUserPin((string) $row['subject_id'], (string) $newPin),
            default => throw new RuntimeException('Invalid recovery type'),
        };

        $mark = $this->pdo->prepare('UPDATE recovery_tokens SET used_at = NOW() WHERE id::text = :id');
        $mark->execute(['id' => (string) $row['id']]);
    }

    private function issueToken(string $type, string $subjectId, ?string $createdByUserId): string
    {
        $raw = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $this->ttlMinutes . ' minutes')->format('Y-m-d H:i:sP');
        $stmt = $this->pdo->prepare(
            'INSERT INTO recovery_tokens (id, type, subject_id, token_hash, expires_at, created_by_user_id, created_at)
             VALUES (:id, :type, :subject_id, :token_hash, :expires_at, :created_by, NOW())'
        );
        $stmt->execute([
            'id' => Uuid::v4()->toRfc4122(),
            'type' => $type,
            'subject_id' => $subjectId,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expiresAt,
            'created_by' => $createdByUserId,
        ]);

        return $raw;
    }

    private function buildLink(string $type, string $rawToken): string
    {
        return rtrim($this->frontendBaseUrl, '/') . '/recover?token=' . urlencode($rawToken) . '&type=' . urlencode($type);
    }

    private function sendEmail(string $email, string $subject, string $body): void
    {
        if ($this->mailer === null) {
            return;
        }

        $this->mailer->send($email, $subject, $body);
    }

    private function applyClinicPassword(string $clinicId, string $password): void
    {
        if (strlen($password) < 6) {
            throw new RuntimeException('Invalid password');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare('UPDATE clinics SET password_hash = :hash WHERE id::text = :id');
        $stmt->execute(['hash' => $hash, 'id' => $clinicId]);
    }

    private function applyUserPassword(string $userId, string $password): void
    {
        if (strlen($password) < 6) {
            throw new RuntimeException('Invalid password');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, is_locked = FALSE, locked_at = NULL, updated_at = NOW() WHERE id::text = :id'
        );
        $stmt->execute(['hash' => $hash, 'id' => $userId]);
    }

    private function applyUserPin(string $userId, string $pin): void
    {
        PinValidator::assertValid($pin);
        $hash = password_hash($pin, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare('UPDATE users SET pin_hash = :hash, updated_at = NOW() WHERE id::text = :id');
        $stmt->execute(['hash' => $hash, 'id' => $userId]);
    }
}
