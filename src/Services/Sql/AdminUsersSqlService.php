<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use Throwable;
use RuntimeException;

final class AdminUsersSqlService
{
    public function __construct(private readonly PDO $pdo, private readonly int $maxActiveAdmins) {}

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, name, role, password_hash, is_active, last_login_at FROM admin_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => self::normalizeEmail($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function isUserStillValid(int $id, string $email): bool
    {
        return $this->findValidUserByIdAndEmail($id, $email) !== null;
    }

    public function findValidUserByIdAndEmail(int $id, string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, name, role FROM admin_users WHERE id = :id AND email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $id, ':email' => self::normalizeEmail($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateLastLoginAt(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn();
    }

    public function createAdmin(string $email, string $name, string $passwordHash, string $role = 'admin'): int
    {
        $email = self::normalizeEmail($email);
        try {
            $this->pdo->beginTransaction();
            $this->pdo->query('SELECT id FROM admin_users WHERE is_active = 1 FOR UPDATE')->fetchAll();
            if ($this->countActiveAdmins() >= $this->maxActiveAdmins) {
                throw new RuntimeException(sprintf('Maximum of %d active admins reached.', $this->maxActiveAdmins));
            }
            if ($this->findByEmail($email) !== null) {
                throw new RuntimeException('An admin with this email address already exists.');
            }
            $stmt = $this->pdo->prepare('INSERT INTO admin_users (email, name, role, password_hash) VALUES (:email, :name, :role, :password_hash)');
            $stmt->execute([':email' => $email, ':name' => trim($name), ':role' => trim($role), ':password_hash' => $passwordHash]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
