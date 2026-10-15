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
        public string $encryption,
        public string $fromEmail,
        public string $fromName,
        public string $plannerEmail,
        public ?string $testReceiverEmail,
        public array $ccEmails,
        public string $appEnv,
        public int $smtpDebug = 0,
        public array $bookingRequestBccEmails = [],
    ) {}

    /** Route the delivery envelope only; stored school contact details stay unchanged.
     * @return array{to:string,cc:array,bcc:array}
     */
    public function recipients(string $to, array $cc = [], array $bcc = []): array
    {
        if ($this->appEnv !== 'development') return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
        $receiver = trim((string) $this->testReceiverEmail);
        if (!filter_var($receiver, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Stel een geldig development-mailadres in voordat u testmail verstuurt.');
        }
        return ['to' => $receiver, 'cc' => [], 'bcc' => []];
    }

    /** @return list<string> */
    public function requestBcc(): array
    {
        return $this->appEnv === 'production' ? $this->bookingRequestBccEmails : [];
    }
}
