<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

use GeoFort\Services\Booking\Data\BookingRequestData;

final readonly class BookingRejectionMailTemplate
{
    public function __construct(
        private MailLayout $layout,
        private MailLinks $links,
        private MailContentBlocks $contentBlocks,
    ) {}

    public function subject(BookingRequestData $request): string
    {
        unset($request);

        return 'Helaas is de gekozen datum voor jullie schoolbezoek niet beschikbaar';
    }

    public function html(BookingRequestData $request): string
    {
        $firstName = $this->escape(
            trim($request->contactpersoonVoornaam),
        );

        return $this->layout->render(
            'Aanvraag schoolbezoek GeoFort',
            'De gekozen datum is niet beschikbaar',
            '<p style="' . MailStyles::paragraph() . '">'
            . 'Beste ' . $firstName . ','
            . '</p>'

            . '<p style="' . MailStyles::paragraph() . '">'
            . 'Hartelijk dank voor jullie aanvraag voor een onderwijsdag bij GeoFort. '
            . 'We waarderen jullie interesse in GeoFort en ons onderwijsprogramma enorm.'
            . '</p>'

            . $this->contentBlocks->visitDateHighlightHtml(
                $request->bezoekdatumLabel,
            )

            . '<p style="' . MailStyles::paragraph() . '">'
            . 'Helaas moeten we jullie laten weten dat deze datum niet beschikbaar is. '
            . 'Daarom kunnen we de aanvraag voor deze datum niet definitief bevestigen.'
            . '</p>'

            . $this->alternativeDateContactHtml()

            . $this->contentBlocks->educationalOfferHtml(),
        );
    }

    public function text(BookingRequestData $request): string
    {
        return "De gekozen datum is niet beschikbaar\n\n"
            . "Beste {$request->contactpersoonVoornaam},\n\n"

            . 'Hartelijk dank voor jullie aanvraag voor een onderwijsdag bij GeoFort. '
            . "We waarderen jullie interesse in GeoFort en ons onderwijsprogramma enorm.\n\n"

            . $this->contentBlocks->visitDateHighlightText(
                $request->bezoekdatumLabel,
            )
            . "\n\n"

            . 'Helaas moeten we jullie laten weten dat deze datum niet beschikbaar is. '
            . "Daarom kunnen we de aanvraag voor deze datum niet definitief bevestigen.\n\n"

            . $this->alternativeDateContactText()
            . "\n\n"

            . $this->contentBlocks->educationalOfferText();
    }

    private function alternativeDateContactHtml(): string
    {
        return '<p style="' . MailStyles::paragraph() . '">'
            . 'Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. '
            . 'Neem gerust <a href="' . $this->escape($this->links->onderwijsMailtoUrl()) . '" style="' . MailStyles::contactLink() . '">'
            . 'contact met ons op'
            . '</a>, dan denken we graag met jullie mee over een alternatieve datum die het beste aansluit bij jullie planning.'
            . '</p>';
    }

    private function alternativeDateContactText(): string
    {
        return 'Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. '
            . 'Neem gerust contact met ons op, dan denken we graag met jullie mee over een alternatieve datum '
            . 'die het beste aansluit bij jullie planning.'
            . "\n"
            . $this->links->onderwijsMailtoUrl();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
