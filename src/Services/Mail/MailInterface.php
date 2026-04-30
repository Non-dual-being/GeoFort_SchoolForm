<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

interface MailInterface
{
    /**
     * @param list<string> $bcc
     * @param list<Attachment> $attachments
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $bcc = [],
        array $attachments = []
    ): void;
}