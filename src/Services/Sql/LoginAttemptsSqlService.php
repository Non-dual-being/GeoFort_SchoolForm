<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;

final class LoginAttemptsSqlService
{
    public function __construct(private readonly PDO $pdo) {}

    public function hasActiveLockout(string $email, string $ip): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM login_attempts WHERE lockout_until > CURRENT_TIMESTAMP AND (email_address = :email OR ip_address = :ip) LIMIT 1');
        $stmt->execute([':email' => $email, ':ip' => $ip]);
        return $stmt->fetchColumn() !== false;
    }

    public function countRecentFailuresByEmail(string $email, int $windowSeconds): int
    {
        return $this->countRecent('email_address', $email, $windowSeconds);
    }

    public function countRecentFailuresByIp(string $ip, int $windowSeconds): int
    {
        return $this->countRecent('ip_address', $ip, $windowSeconds);
    }

    public function recordFailure(string $email, string $ip, ?string $userAgent, ?int $lockoutSeconds): void
    {
        $sql = 'INSERT INTO login_attempts (email_address, ip_address, successful, lockout_until, user_agent) VALUES (:email, :ip, 0, '
            . ($lockoutSeconds === null ? 'NULL' : 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :lockout SECOND)') . ', :user_agent)';
        $stmt = $this->pdo->prepare($sql);
        $params = [':email' => $email, ':ip' => $ip, ':user_agent' => self::userAgent($userAgent)];
        if ($lockoutSeconds !== null) {
            $params[':lockout'] = $lockoutSeconds;
        }
        $stmt->execute($params);
    }

    public function recordSuccess(string $email, string $ip, ?string $userAgent): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE login_attempts SET lockout_until = CURRENT_TIMESTAMP WHERE lockout_until > CURRENT_TIMESTAMP AND email_address = :email AND ip_address = :ip');
            $stmt->execute([':email' => $email, ':ip' => $ip]);
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_address, ip_address, successful, user_agent) VALUES (:email, :ip, 1, :user_agent)');
            $stmt->execute([':email' => $email, ':ip' => $ip, ':user_agent' => self::userAgent($userAgent)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function countRecent(string $column, string $value, int $windowSeconds): int
    {
        if (!in_array($column, ['email_address', 'ip_address'], true)) throw new \InvalidArgumentException('Invalid login-attempt column');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM login_attempts failures
             WHERE failures.{$column} = :value
               AND failures.successful = 0
               AND failures.attempt_time >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :window SECOND)
               AND failures.attempt_time > COALESCE(
                   (SELECT MAX(successes.attempt_time)
                    FROM login_attempts successes
                    WHERE successes.{$column} = :value_success
                      AND successes.successful = 1),
                   '1970-01-01 00:00:00'
               )"
        );
        $stmt->bindValue(':value', $value);
        $stmt->bindValue(':value_success', $value);
        $stmt->bindValue(':window', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private static function userAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') return null;
        return mb_substr($userAgent, 0, 255);
    }
}
