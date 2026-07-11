<?php
declare(strict_types=1);

namespace GeoFort\Services\Auth;

final class CsrfTokenService
{
    public function getOrCreate(string $scope): string
    {
        $this->assertScope($scope);
        $token = $_SESSION['_csrf'][$scope] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->rotate($scope);
        }
        return $token;
    }

    public function validate(string $scope, ?string $submittedToken): bool
    {
        $this->assertScope($scope);
        $stored = $_SESSION['_csrf'][$scope] ?? null;
        return is_string($stored) && is_string($submittedToken) && hash_equals($stored, $submittedToken);
    }

    public function rotate(string $scope): string
    {
        $this->assertScope($scope);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf'][$scope] = $token;
        return $token;
    }

    public function remove(string $scope): void
    {
        $this->assertScope($scope);
        unset($_SESSION['_csrf'][$scope]);
    }

    private function assertScope(string $scope): void
    {
        if ($scope === '' || preg_match('/^[a-z0-9_-]+$/', $scope) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF scope');
        }
    }
}
