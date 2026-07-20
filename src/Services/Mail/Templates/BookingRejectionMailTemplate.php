<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

use GeoFort\Services\Booking\Data\BookingRequestData;

final readonly class BookingRejectionMailTemplate
{
    private const LESMODULES_URL = 'https://www.geofort.nl/onderwijs/lesmodules/';
    private const GOGEO_URL = 'https://www.gogeo.nl/lesmodules/';
    private const MINECRAFT_URL = 'https://workshops.geocraft.nl/';

    public function __construct(private MailLayout $layout, private MailLinks $links) {}

    public function subject(BookingRequestData $request): string
    {
        unset($request);

        return 'Helaas is de gekozen datum voor jullie schoolbezoek niet beschikbaar';
    }

    public function html(BookingRequestData $request): string
    {
        $firstName = $this->escape(trim($request->contactpersoonVoornaam));
        $date = $this->escape($request->bezoekdatumLabel);

        return $this->layout->render(
            'Aanvraag schoolbezoek GeoFort',
            'De gekozen datum is niet beschikbaar',
            '<p style="' . MailStyles::paragraph() . '">Beste ' . $firstName . ',</p>'
            . '<p style="' . MailStyles::paragraph() . '">Hartelijk dank voor jullie aanvraag voor een onderwijsdag bij GeoFort op <strong>' . $date . '</strong>. We waarderen jullie interesse in GeoFort en ons onderwijsprogramma enorm.</p>'
            . '<p style="' . MailStyles::paragraph() . '">Helaas moeten we jullie laten weten dat de gekozen datum niet beschikbaar is. Daarom kunnen we de aanvraag voor deze datum niet definitief bevestigen.</p>'
            . '<p style="' . MailStyles::paragraph() . '">Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. We denken graag met jullie mee over een alternatieve datum waarop voldoende ruimte beschikbaar is. Neem gerust contact met ons op, dan bekijken we samen welke mogelijkheden het beste aansluiten bij jullie planning.</p>'
            . $this->informationBlock()
            . '<p style="' . MailStyles::paragraph() . '">Heb je vragen of wil je overleggen over een andere datum? Stuur ons dan gerust <a href="mailto:' . $this->escapeAttr($this->links->onderwijsEmail) . '" style="' . MailStyles::link() . '">een e-mail</a>. Ons onderwijsteam helpt je graag verder.</p>'
            . $this->educationLinks(),
        );
    }

    public function text(BookingRequestData $request): string
    {
        return "De gekozen datum is niet beschikbaar\n\nBeste {$request->contactpersoonVoornaam},\n\n"
            . "Hartelijk dank voor jullie aanvraag voor een onderwijsdag bij GeoFort op {$request->bezoekdatumLabel}. We waarderen jullie interesse in GeoFort en ons onderwijsprogramma enorm.\n\n"
            . "Helaas moeten we jullie laten weten dat de gekozen datum niet beschikbaar is. Daarom kunnen we de aanvraag voor deze datum niet definitief bevestigen.\n\n"
            . "Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. We denken graag met jullie mee over een alternatieve datum waarop voldoende ruimte beschikbaar is. Neem gerust contact met ons op, dan bekijken we samen welke mogelijkheden het beste aansluiten bij jullie planning.\n\n"
            . "Meer informatie over een schoolbezoek\nEen volledig overzicht van de prijzen, voorwaarden en algemene afspraken rondom een schoolbezoek vind je op onze website:\n{$this->links->voorwaardenUrl}\n\n"
            . "Heb je vragen of wil je overleggen over een andere datum? Stuur ons dan gerust een e-mail via {$this->links->onderwijsEmail}. Ons onderwijsteam helpt je graag verder.\n\n"
            . "GeoFort-lesmodules\nBekijk het educatieve aanbod en de lesmodules van GeoFort.\n" . self::LESMODULES_URL . "\n\n"
            . "GoGeo online lesmodules\nOntdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.\n" . self::GOGEO_URL . "\n\n"
            . "Minecraft in de klas\nBoek een interactieve Minecraft-workshop bij jullie op school of op locatie.\n" . self::MINECRAFT_URL;
    }

    private function informationBlock(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::infoTable() . '"><tr><td style="' . MailStyles::sectionHeaderCell() . '">Meer informatie over een schoolbezoek</td></tr><tr><td style="' . MailStyles::valueCell() . '">Een volledig overzicht van de prijzen, voorwaarden en algemene afspraken rondom een schoolbezoek vind je op onze website. <a href="' . $this->escapeAttr($this->links->voorwaardenUrl) . '" style="' . MailStyles::link() . '">Bekijk afspraken en kosten voor een schoolbezoek</a>.</td></tr></table><p style="' . MailStyles::paragraph() . '">&nbsp;</p>';
    }

    private function educationLinks(): string
    {
        $items = [
            ['GeoFort-lesmodules', 'Bekijk het educatieve aanbod en de lesmodules van GeoFort.', self::LESMODULES_URL],
            ['GoGeo online lesmodules', 'Ontdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.', self::GOGEO_URL],
            ['Minecraft in de klas', 'Boek een interactieve Minecraft-workshop bij jullie op school of op locatie.', self::MINECRAFT_URL],
        ];
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::infoTable() . '">';
        foreach ($items as [$title, $description, $url]) {
            $html .= '<tr><td style="' . MailStyles::valueCell() . '"><a href="' . $this->escapeAttr($url) . '" style="' . MailStyles::link() . '"><strong>' . $this->escape($title) . '</strong></a><br>' . $this->escape($description) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
