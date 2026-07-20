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
            . '<p style="' . MailStyles::paragraph() . '">Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. Neem gerust <a href="mailto:' . $this->escapeAttr($this->links->onderwijsEmail) . '" style="' . MailStyles::link() . '">contact met ons op</a>, dan denken we graag met jullie mee over een alternatieve datum die het beste aansluit bij jullie planning.</p>'
            . $this->informationBlock()
            . '<p style="' . MailStyles::paragraph() . '">Heb je nog vragen? Ons onderwijsteam helpt je graag verder.</p>',
        );
    }

    public function text(BookingRequestData $request): string
    {
        return "De gekozen datum is niet beschikbaar\n\nBeste {$request->contactpersoonVoornaam},\n\n"
            . "Hartelijk dank voor jullie aanvraag voor een onderwijsdag bij GeoFort op {$request->bezoekdatumLabel}. We waarderen jullie interesse in GeoFort en ons onderwijsprogramma enorm.\n\n"
            . "Helaas moeten we jullie laten weten dat de gekozen datum niet beschikbaar is. Daarom kunnen we de aanvraag voor deze datum niet definitief bevestigen.\n\n"
            . "Dat betekent natuurlijk niet dat een bezoek aan GeoFort niet mogelijk is. Neem gerust contact met ons op via {$this->links->onderwijsEmail}, dan denken we graag met jullie mee over een alternatieve datum die het beste aansluit bij jullie planning.\n\n"
            . "Ontdek meer over ons educatief aanbod\n"
            . "Afspraken en kosten schoolbezoek\nBekijk de voorwaarden en algemene afspraken rondom een schoolbezoek.\n{$this->links->voorwaardenUrl}\n\n"
            . "Online boekingsformulier\nDien een nieuwe aanvraag in zodra jullie een geschikte datum hebben gevonden.\n{$this->bookingUrl()}\n\n"
            . "GeoFort-lesmodules\nBekijk het educatieve aanbod en de lesmodules van GeoFort.\n" . self::LESMODULES_URL . "\n\n"
            . "GoGeo online lesmodules\nOntdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.\n" . self::GOGEO_URL . "\n\n"
            . "Minecraft in de klas\nBoek een interactieve Minecraft-workshop bij jullie op school of op locatie.\n" . self::MINECRAFT_URL . "\n\n"
            . "Heb je nog vragen? Ons onderwijsteam helpt je graag verder.";
    }

    private function informationBlock(): string
    {
        $items = [
            ['Afspraken en kosten schoolbezoek', 'Bekijk de voorwaarden en algemene afspraken rondom een schoolbezoek.', $this->links->voorwaardenUrl],
            ['Online boekingsformulier', 'Dien een nieuwe aanvraag in zodra jullie een geschikte datum hebben gevonden.', $this->bookingUrl()],
            ['GeoFort-lesmodules', 'Bekijk het educatieve aanbod en de lesmodules van GeoFort.', self::LESMODULES_URL],
            ['GoGeo online lesmodules', 'Ontdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.', self::GOGEO_URL],
            ['Minecraft in de klas', 'Boek een interactieve Minecraft-workshop bij jullie op school of op locatie.', self::MINECRAFT_URL],
        ];
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:' . MailStyles::COLOR_LIGHT_BLUE . ';border:1px solid ' . MailStyles::COLOR_BORDER . ';margin:4px 0 18px 0;">'
            . '<tr><td style="padding:16px 18px 10px 18px;">'
            . '<h2 style="' . MailStyles::sectionTitle() . '">Ontdek meer over ons educatief aanbod</h2>'
            . '<p style="margin:6px 0 0 0;font-family:' . MailStyles::FONT_FAMILY . ';font-size:14px;line-height:20px;color:' . MailStyles::COLOR_TEXT . ';">Bekijk praktische informatie en ontdek ons aanbod voor in en buiten de klas.</p>'
            . '</td></tr>';
        foreach ($items as [$title, $description, $url]) {
            $html .= '<tr><td style="padding:11px 18px;border-top:1px solid ' . MailStyles::COLOR_BORDER . ';font-family:' . MailStyles::FONT_FAMILY . ';font-size:14px;line-height:20px;color:' . MailStyles::COLOR_TEXT . ';">'
                . '<a href="' . $this->escapeAttr($url) . '" style="' . MailStyles::link() . '">' . $this->escape($title) . '</a><br>'
                . $this->escape($description) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private function bookingUrl(): string
    {
        return rtrim($this->links->baseUrl, '/') . '/';
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
