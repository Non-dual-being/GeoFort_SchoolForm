<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Data\StoredBookingMailDataFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Booking\Roster\RosterAttachmentResolver;
use GeoFort\Services\Booking\Status\BookingPlannerStatusMailSenderInterface;
use GeoFort\Services\Mail\Attachments\PublicDocumentAttachmentResolver;
use GeoFort\Services\Mail\Templates\BookingRejectionMailTemplate;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use Throwable;

final readonly class BookingStatusMailSender implements BookingPlannerStatusMailSenderInterface
{
    private const BUS_ROUTE_PATH = '/assets/booking/documents/Routekaart_Bussen_GeoFort_Onderwijs.pdf';

    public function __construct(
        private MailInterface $mailer,
        private MailConfig $config,
        private BookingRequestMailTemplate $confirmationTemplate,
        private BookingRejectionMailTemplate $rejectionTemplate,
        private StoredBookingMailDataFactory $dataFactory,
        private BookingRosterResolver $rosters,
        private RosterAttachmentResolver $rosterAttachments,
        private PublicDocumentAttachmentResolver $documents,
    ) {}

    public function sendConfirmation(StoredBooking $booking, BookingPriceQuote $quote): void
    {
        $this->confirmation($booking, $quote, null);
    }

    public function sendPlannerConfirmation(StoredBooking $booking, BookingPriceQuote $quote, Attachment $roster): void
    {
        $this->confirmation($booking, $quote, $roster);
    }

    private function confirmation(StoredBooking $booking, BookingPriceQuote $quote, ?Attachment $plannerRoster): void
    {
        $request = $this->dataFactory->fromStoredBooking($booking);
        $attachments = [];
        $rosterText = 'Het conceptrooster kon niet worden meegestuurd. Het GeoFort Onderwijs Team stuurt het rooster later na.';
        $routeText = 'De route- en parkeerinformatie kon niet worden meegestuurd.';
        if ($plannerRoster !== null) {
            $attachments[] = $plannerRoster;
            $rosterText = 'In de bijlage vindt u het definitieve lesrooster voor uw bezoek. Dit rooster is door onze GeoFort-planner voor uw school opgesteld.';
        } else try {
            $roster = $this->rosters->resolve($request->schoolSector, $request->programma, $request->educationSelection, $request->keuzemoduleKey, $request->aantalLeerlingen, $request->aantalBegeleiders);
            if ($roster->available) {
                $resolved = $this->rosterAttachments->resolve($roster->pdfUrl);
                if ($resolved->found && $resolved->path !== null) {
                    $attachments[] = new Attachment($resolved->path, $resolved->filename, 'application/pdf');
                    $rosterText = 'In de bijlage treft u het conceptrooster aan. Het definitieve rooster kan hier nog van afwijken.';
                }
            }
        } catch (Throwable) {
            // De bevestigingsmail blijft verzendbaar zonder roosterbijlage.
        }
        try {
            $route = $this->documents->resolve(self::BUS_ROUTE_PATH, 'GeoFort_Route_en_Parkeren_bus.pdf');
            if ($route->found && $route->path !== null) {
                $attachments[] = new Attachment($route->path, $route->filename, $route->mimeType ?? 'application/pdf');
                $routeText = 'Daarnaast vindt u in de bijlage route- en parkeerinformatie voor de busrit.';
            }
        } catch (Throwable) {
            // De bevestigingsmail blijft verzendbaar zonder routebijlage.
        }
        $this->send($request->email, trim($request->contactpersoonVoornaam . ' ' . $request->contactpersoonAchternaam),
            'Bevestiging schoolbezoek GeoFort - ' . $request->schoolnaam,
            $this->confirmationTemplate->confirmationHtml($request, $rosterText, $quote, $routeText),
            $this->confirmationTemplate->confirmationText($request, $rosterText, $quote, $routeText), $attachments);
    }

    public function sendRejection(StoredBooking $booking): void
    {
        $request = $this->dataFactory->fromStoredBooking($booking);
        $this->send($request->email, trim($request->contactpersoonVoornaam . ' ' . $request->contactpersoonAchternaam),
            $this->rejectionTemplate->subject($request), $this->rejectionTemplate->html($request), $this->rejectionTemplate->text($request), []);
    }

    private function send(string $email, string $name, string $subject, string $html, string $text, array $attachments): void
    {
        $to = $this->config->appEnv === 'production' ? $email : (trim((string) $this->config->testReceiverEmail) ?: $this->config->plannerEmail);
        $this->mailer->send($to, $name, $subject, $html, $text, $this->config->ccEmails, $attachments);
    }
}
