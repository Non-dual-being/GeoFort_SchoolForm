<?php
declare(strict_types=1);
namespace GeoFort\Services\Mail;

use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Booking\Roster\RosterAttachmentResolver;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use Throwable;

final readonly class BookingMailService
{
    private const ROSTER_ATTACHMENT_SUCCESS_TEXT = 'In de bijlage treft u het conceptrooster aan. Het definitieve rooster kan hier nog van afwijken.';
    private const ROSTER_ATTACHMENT_FALLBACK_TEXT = 'Het conceptrooster kon niet worden meegestuurd. Geen zorgen: het GeoFort Onderwijs Team stuurt het rooster later nog na.';

    public function __construct(
        private MailInterface $mailer,
        private MailConfig $config,
        private BookingRequestMailTemplate $template,
        private ?BookingRosterResolver $bookingRosterResolver = null,
        private ?RosterAttachmentResolver $rosterAttachmentResolver = null,
    ){}

    public function sendRequestReceivedMail(BookingRequestData $request): void {
        $toEmail = $this->resolveReceiverEmail();
        $toName = $request->schoolnaam;
        $attachments = [];

        $rosterAttachment = $this->resolveRosterAttachment($request);

        if ($rosterAttachment instanceof Attachment) {
            $attachments[] = $rosterAttachment;
        }

        if ($attachments !== []) {
            try {
                $this->sendMail(
                    request: $request,
                    toEmail: $toEmail,
                    toName: $toName,
                    rosterAttachmentText: self::ROSTER_ATTACHMENT_SUCCESS_TEXT,
                    attachments: $attachments,
                );
                return;
            } catch (Throwable $e) {
                error_log(
                    'Roosterbijlage kon niet worden toegevoegd; mail wordt zonder bijlage opnieuw verzonden: '
                    . $e->getMessage(),
                );
            }
        }

        $this->sendMail(
            request: $request,
            toEmail: $toEmail,
            toName: $toName,
            rosterAttachmentText: self::ROSTER_ATTACHMENT_FALLBACK_TEXT,
            attachments: [],
        );
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function sendMail(
        BookingRequestData $request,
        string $toEmail,
        string $toName,
        string $rosterAttachmentText,
        array $attachments,
    ): void {
        $this->mailer->send(
            toEmail:    $toEmail,
            toName:     $toName,
            subject:    $this->template->subject($request),
            htmlBody:   $this->template->html($request, $rosterAttachmentText),
            textBody:   $this->template->text($request, $rosterAttachmentText),
            bcc:        $this->resolveBcc(),
            attachments: $attachments,
        );
    }

    private function resolveRosterAttachment(BookingRequestData $request): ?Attachment
    {
        if (
            !$this->bookingRosterResolver instanceof BookingRosterResolver
            || !$this->rosterAttachmentResolver instanceof RosterAttachmentResolver
        ) {
            error_log('Roosterbijlage niet toegevoegd: rooster-resolvers ontbreken.');
            return null;
        }

        try {
            $roster = $this->bookingRosterResolver->resolve(
                schoolSector: $request->schoolSector,
                program: $request->programma,
                educationSelection: $request->educationSelection,
                choiceModuleKey: $request->keuzemoduleKey,
                studentCount: $request->aantalLeerlingen,
                supervisorCount: $request->aantalBegeleiders,
            );

            if (!$roster->available) {
                error_log('Roosterbijlage niet toegevoegd: geen rooster beschikbaar.');
                return null;
            }

            $attachmentResult = $this->rosterAttachmentResolver->resolve(
                $roster->pdfUrl,
            );

            if (!$attachmentResult->found || $attachmentResult->path === null) {
                error_log(
                    'Roosterbijlage niet toegevoegd: '
                    . ($attachmentResult->reason ?? 'onbekende reden'),
                );
                return null;
            }

            return new Attachment(
                path: $attachmentResult->path,
                filename: $attachmentResult->filename,
                mimeType: 'application/pdf',
            );
        } catch (Throwable $e) {
            error_log('Roosterbijlage niet toegevoegd: ' . $e->getMessage());
            return null;
        }
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

           
        }

        return [];
    }

}
