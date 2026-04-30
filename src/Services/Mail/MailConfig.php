<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

final readonly class MailConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $fromEmail,
        public string $fromName,
        public string $plannerEmail,
        public ?string $testReceiverEmail,
        public string $appEnv,
    ) {}
}