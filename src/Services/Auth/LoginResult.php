<?php
declare(strict_types=1);

namespace GeoFort\Services\Auth;

final readonly class LoginResult
{
    public function __construct(
        public LoginStatus $status,
        public ?array $user = null,
        public ?string $previousLoginAt = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === LoginStatus::Success;
    }
}
