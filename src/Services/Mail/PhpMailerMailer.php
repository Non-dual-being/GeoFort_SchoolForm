<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final readonly class PhpMailerMailer implements MailInterface
{
    public function __construct(
        private MailConfig $config,
    ) {}

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $cc = [],
        array $attachments = [],
    ): void {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ongeldig ontvangeradres.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $mail->Host = $this->config->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->config->username;
            $mail->Password = $this->config->password;
            $mail->SMTPSecure = $this->resolveEncryption();
            $mail->Port = $this->config->port;

            $mail->SMTPDebug = $this->config->smtpDebug;
            $mail->Debugoutput = static function (
                string $str,
                int $level
            ): void {
                error_log("SMTP debug [$level]: $str");
            };

            $mail->setFrom($this->config->fromEmail, $this->config->fromName);
            $mail->addAddress($toEmail, $toName);

            foreach ($cc as $ccEmail) {
                if (!filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Ongeldig CC-adres.');
                }

                $mail->addCC($ccEmail);
            }

            foreach ($attachments as $attachment) {
                if (!$attachment instanceof Attachment) {
                    throw new RuntimeException('Ongeldige attachment meegegeven.');
                }

                if (!is_file($attachment->path)) {
                    throw new RuntimeException(
                        'Attachment bestaat niet: ' . $attachment->filename
                    );
                }

                $mail->addAttachment(
                    $attachment->path,
                    $attachment->filename,
                    PHPMailer::ENCODING_BASE64,
                    $attachment->mimeType ?? '',
                );
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
        } catch (Throwable $e) {
            $errorInfo = $this->redactSecrets(trim($mail->ErrorInfo));
            $message = 'Mail kon niet worden verzonden.';

            if ($errorInfo !== '') {
                $message .= ' PHPMailer ErrorInfo: ' . $errorInfo;
            }

            throw new RuntimeException(
                $message,
                0,
                $e
            );
        }
    }

    private function resolveEncryption(): string
    {
        return match (strtolower(trim($this->config->encryption))) {
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
    }

    private function redactSecrets(string $message): string
    {
        $password = $this->config->password;

        if ($password === '') {
            return $message;
        }

        return str_replace($password, '[redacted]', $message);
    }
}
