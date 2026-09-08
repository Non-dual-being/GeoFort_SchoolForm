<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

interface MailInterface
{
    /**
     * @param list<string> $cc
     * @param list<Attachment> $attachments
     * @param list<string> $bcc
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $cc = [],
        array $attachments = [],
        array $bcc = [],
    ): void;
}
