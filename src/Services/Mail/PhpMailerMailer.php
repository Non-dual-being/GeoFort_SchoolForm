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
        array $bcc = [],
        array $attachments = [],
    ): void {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ongeldig ontvangeradres.');
        }

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $mail->Host = $this->config->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->config->username;
            $mail->Password = $this->config->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
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

            foreach ($bcc as $bccEmail) {
                if (!filter_var($bccEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Ongeldig BCC-adres.');
                }

                $mail->addBCC($bccEmail);
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

                $mail->addAttachment($attachment->path, $attachment->filename);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Mail kon niet worden verzonden.',
                0,
                $e
            );
        }
    }
}