<?php
declare(strict_types=1);
namespace GeoFort\Services\Mail\Templates;
use GeoFort\Services\Booking\BookingRequestData;

final readonly class BookingRequestMailTemplate
{
    public function __construct(
        private MailLayout $layout,
        private MailLinks $links,
    ) {}

    public function subject(BookingRequestData $request): string
    {
        return 'Aanvraag schoolbezoek GeoFort - ' . $request->schoolnaam;
    }

    public function html(BookingRequestData $request): string
    {

        $voorwaardenLink = '';

        if ($this->links->voorwaardenUrl !== '') {
            $voorwaardenLink = '
                <a href="' . $this->escapeAttr($this->links->voorwaardenUrl) . '" style="' . MailStyles::link() . '">
                    afspraken en kosten schoolbezoek
                </a>';
        }

        $content = '
            <p style="' . MailStyles::paragraph() . '">
                Er is een nieuwe aanvraag voor een schoolbezoek bij GeoFort ontvangen.
            </p>

            <p style="' . MailStyles::paragraph() . '">
                Hieronder staan de gegevens die op dit moment uit het formulier worden verwerkt.
            </p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::infoTable() . '">
                <tr>
                    <td colspan="2" style="' . MailStyles::infoHeaderCell() . '">
                        Aanvraaggegevens
                    </td>
                </tr>
                ' . $this->row('Schoolnaam', $request->schoolnaam) . '
                ' . $this->row('Land', $request->land) . '
                ' . $this->row('Adres', $request->adres) . '
                ' . $this->row('Postcode', $request->postcode) . '
                ' . $this->row('Plaats', $request->plaats) . '
                ' . $this->row('School telefoonnummer', $request->schoolTelefoonnummer) . '
                ' . $this->row('Telefoonnummer contactpersoon', $request->contactpersoonTelefoonnummer) . '
                ' . $this->row('Voornaam contactpersoon', $request->contactpersoonVoornaam) . '
                ' . $this->row('Achternaam contactpersoon', $request->contactpersoonAchternaam) . '
            </table>

            <p style="' . MailStyles::paragraph() . '">&nbsp;</p>

            <p style="' . MailStyles::paragraph() . '">
                Deze aanvraag is automatisch verzonden vanuit het boekingsformulier.
            </p>';

        if ($voorwaardenLink !== '') {
            $content .= '
                <p style="' . MailStyles::paragraph() . '">
                    Bekijk eventueel ook de ' . $voorwaardenLink . '.
                </p>';
        }

        return $this->layout->render(
            title: 'Aanvraag schoolbezoek GeoFort',
            subtitle: 'Nieuwe aanvraag ontvangen',
            contentHtml: $content,
        );
    }

    public function text(BookingRequestData $request): string
    {
        return implode("\n", [
            'Aanvraag schoolbezoek GeoFort',
            '',
            'Er is een nieuwe aanvraag ontvangen.',
            '',
            'Schoolnaam: ' . $request->schoolnaam,
            'Land: ' . $request->land,
            'Adres: ' . $request->adres,
            'Postcode: ' . $request->postcode,
            'Plaats: ' . $request->plaats,
            'School telefoonnummer: ' . $request->schoolTelefoonnummer,
            'Telefoonnummer contactpersoon: ' . $request->contactpersoonTelefoonnummer,
            'Voornaam contactpersoon: ' . $request->contactpersoonVoornaam,
            'Achternaam contactpersoon: ' . $request->contactpersoonAchternaam,
            '',
            'Met vriendelijke groet,',
            'Team Onderwijs - GeoFort',
        ]);
    }

    private function row(string $label, string $value): string
    {
        return '
            <tr>
                <td style="' . MailStyles::labelCell() . '">
                    ' . $this->escape($label) . '
                </td>
                <td style="' . MailStyles::valueCell() . '">
                    ' . $this->escape($value)  . '
                </td>
            </tr>';
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