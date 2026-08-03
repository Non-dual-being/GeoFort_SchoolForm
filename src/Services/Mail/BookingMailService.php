<?php
declare(strict_types=1);
namespace GeoFort\Services\Mail;

use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Booking\Roster\RosterAttachmentResolver;
use GeoFort\Services\Mail\Attachments\PublicDocumentAttachmentResolver;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use Throwable;

final readonly class BookingMailService
{
    private const ROSTER_ATTACHMENT_SUCCESS_TEXT = 'In de bijlage treft u het conceptrooster aan. Het definitieve rooster kan hier nog van afwijken.';
    private const ROSTER_ATTACHMENT_FALLBACK_TEXT = 'Het conceptrooster kon niet worden meegestuurd. Geen zorgen: het GeoFort Onderwijs Team stuurt het rooster later nog na.';
    private const BUS_ROUTE_PUBLIC_PATH = '/assets/booking/documents/Routekaart_Bussen_GeoFort_Onderwijs.pdf';
    private const BUS_ROUTE_ATTACHMENT_FILENAME = 'GeoFort_Route_en_Parkeren_bus.pdf';
    private const BUS_ROUTE_ATTACHMENT_SUCCESS_TEXT = 'Daarnaast vindt u in de bijlage route- en parkeerinformatie voor de busrit.';
    private const BUS_ROUTE_ATTACHMENT_FALLBACK_TEXT = 'De route- en parkeerinformatie kon niet worden meegestuurd. Neem gerust contact op met het GeoFort Onderwijs Team als u deze informatie wilt ontvangen.';

    public function __construct(
        private MailInterface $mailer,
        private MailConfig $config,
        private BookingRequestMailTemplate $template,
        private ?BookingRosterResolver $bookingRosterResolver = null,
        private ?RosterAttachmentResolver $rosterAttachmentResolver = null,
        private mixed $priceCalculator = null,
        private ?PublicDocumentAttachmentResolver $publicDocumentAttachmentResolver = null,
    ){}

    public function sendRequestReceivedMail(BookingRequestData $request, ?BookingPriceQuote $storedQuote = null): void {
        $toEmail = $this->resolveReceiverEmail($request);
        $toName = trim($request->contactpersoonVoornaam . ' ' . $request->contactpersoonAchternaam);

        if ($toName === '') {
            $toName = $request->schoolnaam;
        }

        $attachments = [];
        $priceQuote = $storedQuote;
        $rosterAttachmentText = self::ROSTER_ATTACHMENT_FALLBACK_TEXT;
        $busRouteAttachmentText = self::BUS_ROUTE_ATTACHMENT_FALLBACK_TEXT;

        $rosterAttachment = $this->resolveRosterAttachment($request);

        if ($rosterAttachment instanceof Attachment) {
            $attachments[] = $rosterAttachment;
            $rosterAttachmentText = self::ROSTER_ATTACHMENT_SUCCESS_TEXT;
        }

        $busRouteAttachment = $this->resolveBusRouteAttachment();

        if ($busRouteAttachment instanceof Attachment) {
            $attachments[] = $busRouteAttachment;
            $busRouteAttachmentText = self::BUS_ROUTE_ATTACHMENT_SUCCESS_TEXT;
        }

        if ($attachments !== []) {
            try {
                $this->sendMail(
                    request: $request,
                    toEmail: $toEmail,
                    toName: $toName,
                    rosterAttachmentText: $rosterAttachmentText,
                    busRouteAttachmentText: $busRouteAttachmentText,
                    priceQuote: $priceQuote,
                    attachments: $attachments,
                );
                return;
            } catch (Throwable $e) {
                error_log(
                    'Mail met bijlagen kon niet worden verzonden; er wordt opnieuw geprobeerd zonder bijlagen: '
                    . $e->getMessage(),
                );
            }
        }

        try {
            $this->sendMail(
                request: $request,
                toEmail: $toEmail,
                toName: $toName,
                rosterAttachmentText: self::ROSTER_ATTACHMENT_FALLBACK_TEXT,
                busRouteAttachmentText: self::BUS_ROUTE_ATTACHMENT_FALLBACK_TEXT,
                priceQuote: $priceQuote,
                attachments: [],
            );
        } catch (Throwable $e) {
            error_log(
                'Mail zonder bijlagen kon ook niet worden verzonden: '
                . $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function sendMail(
        BookingRequestData $request,
        string $toEmail,
        string $toName,
        string $rosterAttachmentText,
        string $busRouteAttachmentText,
        ?BookingPriceQuote $priceQuote,
        array $attachments,
    ): void {
        $this->mailer->send(
            toEmail:    $toEmail,
            toName:     $toName,
            subject:    $this->template->subject($request),
            htmlBody:   $this->template->html($request, $rosterAttachmentText, $priceQuote, $busRouteAttachmentText),
            textBody:   $this->template->text($request, $rosterAttachmentText, $priceQuote, $busRouteAttachmentText),
            cc:         $this->resolveCc(),
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

    private function resolveBusRouteAttachment(): ?Attachment
    {
        if (!$this->publicDocumentAttachmentResolver instanceof PublicDocumentAttachmentResolver) {
            error_log('Busroutebijlage niet toegevoegd: document-resolver ontbreekt.');
            return null;
        }

        try {
            $result = $this->publicDocumentAttachmentResolver->resolve(
                publicUrlPath: self::BUS_ROUTE_PUBLIC_PATH,
                attachmentFilename: self::BUS_ROUTE_ATTACHMENT_FILENAME,
            );

            if (!$result->found || $result->path === null) {
                error_log(
                    'Busroutebijlage niet toegevoegd: '
                    . ($result->reason ?? 'onbekende reden'),
                );
                return null;
            }

            return new Attachment(
                path: $result->path,
                filename: $result->filename,
                mimeType: $result->mimeType ?? 'application/pdf',
            );
        } catch (Throwable $e) {
            error_log('Busroutebijlage niet toegevoegd: ' . $e->getMessage());
            return null;
        }
    }

    private function resolveReceiverEmail(BookingRequestData $request): string
    {
        if ($this->config->appEnv === "production") {
            return $request->email;
        }

        $testReceiverEmail = trim((string) $this->config->testReceiverEmail);

        return $testReceiverEmail !== ''
            ? $testReceiverEmail
            : $this->config->plannerEmail;

    }

    /**
     
    * @return list<string>
    
    */

    private function resolveCc(): array
    {
        return $this->config->ccEmails;
    }

}
