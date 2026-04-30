<?php
declare(strict_types=1);
namespace GeoFort\Services\Mail;

use GeoFort\Services\Booking\BookingRequestData;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;

final readonly class BookingMailService
{
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $config,
        private BookingRequestMailTemplate $template,
    ){}

    public function sendRequestReceivedMail(BookingRequestData $request): void {
        $toEmail = $this->resolveReceiverEmail();
        $toName = $request->schoolnaam;

        $toEmail = $this->mailer->send(
            toEmail:    $toEmail,
            toName:     $toName,
            subject:    $this->template->subject(),
            htmlBody:   $this->template->text($request),
            bcc:        $this->resolveBcc(),
            attachments: []
        );

    }

    private function resolveReceiverEmail(): string
    {
        if ($this->config->appEnv === "production") {
            return $this->config->plannerEmail;
        }

        return $this->config->testReceiverEmail ?? $this->config->plannerEmail;

    }

    /**
     
    * @return list<string>
    
    */

    private function resolveBcc(): array
    {
        if ($this->config->appEnv === "production") {
            return [
                'kevin@geofort.nl' /**switch to onderwijs@geofort.nl in final fase */
            ];

            return [];
        }
    }

}