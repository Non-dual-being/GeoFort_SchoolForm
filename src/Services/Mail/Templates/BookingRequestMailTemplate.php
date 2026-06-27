<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Validation\Validator;

final readonly class BookingRequestMailTemplate
{
    public function __construct(
        private MailLayout $layout,
        private MailLinks $links,
        private Validator $validator,
    ) {}

    public function subject(BookingRequestData $request): string
    {
        return 'Aanvraag schoolbezoek GeoFort - ' . $request->schoolnaam;
    }

    public function html(BookingRequestData $request): string
    {
        $sectorLabel = BookingProgramConfig::getSchoolSectorLabel(
            $request->schoolSector,
        );

        $tableRows = $this->buildHtmlTableRows($request, $sectorLabel);

        $content = '
            <p style="' . MailStyles::paragraph() . '">
                Er is een nieuwe aanvraag voor een schoolbezoek bij GeoFort ontvangen.
            </p>

            <p style="' . MailStyles::paragraph() . '">
                Hieronder staan de gegevens die op dit moment uit het formulier worden verwerkt.
            </p>

            <table 
                role="presentation" 
                width="100%" 
                cellpadding="0" 
                cellspacing="0" 
                border="0" 
                style="' . MailStyles::infoTable() . '"
                > 
                ' . $tableRows . '
            </table>

            <p style="' . MailStyles::paragraph() . '">&nbsp;</p>

            <p style="' . MailStyles::paragraph() . '">
                Deze aanvraag is automatisch verzonden vanuit het boekingsformulier.
            </p>';

        $voorwaardenLink = $this->voorwaardenLink();

        if ($voorwaardenLink !== '') {
            $content .= '
                <p style="' . MailStyles::paragraph() . '">
                    Bekijk eventueel ook de ' . $voorwaardenLink . '.
                </p>';
        }

        return $this->layout->render(
            title: 'Aanvraag schoolbezoek GeoFort',
            subtitle: 'Nieuwe aanvraag ontvangen voor ' . $request->bezoekdatum,
            contentHtml: $content,
        );
    }

    public function text(BookingRequestData $request): string
    {
        $sectorLabel = BookingProgramConfig::getSchoolSectorLabel(
            $request->schoolSector,
        );

        $lines = [
            'Aanvraag schoolbezoek GeoFort',
            '',
            'Er is een nieuwe aanvraag ontvangen.',
            '',
            'Algemene gegevens',
            'Schoolnaam: ' . $request->schoolnaam,
            'Land: ' . $request->land,
            'Adres: ' . $request->adres,
            'Postcode: ' . $request->postcode,
            'Plaats: ' . $request->plaats,
            'School telefoonnummer: ' . $request->schoolTelefoonnummer,
            'Telefoonnummer contactpersoon: ' . $request->contactpersoonTelefoonnummer,
            'Voornaam contactpersoon: ' . $request->contactpersoonVoornaam,
            'Achternaam contactpersoon: ' . $request->contactpersoonAchternaam,
            'E-mail: ' . $request->email,
            '',
            'Bezoekgegevens',
            'Bezoekdatum: ' . $request->bezoekdatum,
            'Onderwijssector: ' . $sectorLabel,
        ];

        $discoveryLine = $this->discoveryRowText($request, true);

        if ($discoveryLine !== '') {
            $lines[] = '';
            $lines[] = 'Aanvullende gegevens';
            $lines[] = $discoveryLine;
        }

        $lines[] = '';
        $lines[] = 'Kortingsgegevens';
        $lines[] = 'CJP-korting: ' . $request->cjpPasGebruik;

        if ($this->returnCJPRequested($request)) {
            $cjpUser = $this->cjpNameRowText($request, true);
            $cjpPasnummer = $this->cjpCardNumberRowText($request, true);

            if ($cjpUser !== '') {
                $lines[] = $cjpUser;
            }

            if ($cjpPasnummer !== '') {
                $lines[] = $cjpPasnummer;
            }
        }

        $lines = [
            ...$lines,
            '',
            'Met vriendelijke groet,',
            'Team Onderwijs - GeoFort',
        ];

        return implode("\n", $lines);
    }

    private function buildHtmlTableRows(
        BookingRequestData $request,
        string $sectorLabel,
    ): string {
        $rows = '';

        $rows .= $this->overviewHeader('Boekingsoverzicht');
        
        $rows .= $this->section('Algemene gegevens');
        $rows .= $this->row('Schoolnaam', $request->schoolnaam);
        $rows .= $this->row('Land', $request->land);
        $rows .= $this->row('Adres', $request->adres);
        $rows .= $this->row('Postcode', $request->postcode);
        $rows .= $this->row('Plaats', $request->plaats);
        $rows .= $this->row(
            'School telefoonnummer',
            $request->schoolTelefoonnummer,
        );
        $rows .= $this->row(
            'Telefoonnummer contactpersoon',
            $request->contactpersoonTelefoonnummer,
        );
        $rows .= $this->row(
            'Voornaam contactpersoon',
            $request->contactpersoonVoornaam,
        );
        $rows .= $this->row(
            'Achternaam contactpersoon',
            $request->contactpersoonAchternaam,
        );
        $rows .= $this->row('E-mail', $request->email);

        $rows .= $this->section('Bezoekgegevens');
        $rows .= $this->row('Datum van het bezoek', $request->bezoekdatum);
        $rows .= $this->row('Onderwijssector', $sectorLabel);
        $rows .= $this->educationSelectionRows($request);

        $discovery = $this->discoveryRowText($request);

        if ($discovery !== '') {
            $rows .= $this->section('Aanvullende gegevens');
            $rows .= $discovery;
        }

        $rows .= $this->section('Kortingsgegevens');
        $rows .= $this->row('CJP-korting', $request->cjpPasGebruik);

        if ($this->returnCJPRequested($request)) {
            $rows .= $this->cjpNameRowText($request);
            $rows .= $this->cjpCardNumberRowText($request);
        }

        return $rows;
    }

    private function row(string $label, string $value): string
    {
        return '
            <tr>
                <td style="' . MailStyles::labelCell() . '">
                    ' . $this->escape($label) . '
                </td>
                <td style="' . MailStyles::valueCell() . '">
                    ' . $this->escape($value) . '
                </td>
            </tr>';
    }

    private function section(string $label): string
    {
        return '
            <tr>
                <td colspan="2" style="' . MailStyles::sectionHeaderCell() . '">
                    ' . $this->escape($label) . '
                </td>
            </tr>';
    }

    private function voorwaardenLink(): string
    {
        if ($this->links->voorwaardenUrl === '') {
            return '';
        }

        return '
            <a href="' . $this->escapeAttr($this->links->voorwaardenUrl) . '" style="' . MailStyles::link() . '">
                afspraken en kosten schoolbezoek
            </a>';
    }

    private function returnDiscoveryGiven(BookingRequestData $request): bool
    {
        return trim($request->hoeKentUGeoFort) !== '';
    }

    private function discoveryRowText(
        BookingRequestData $request,
        bool $plainText = false,
    ): string {
        if (!$this->returnDiscoveryGiven($request)) {
            return '';
        }

        $text = $this->getDiscoveryText($request->hoeKentUGeoFort);

        if ($plainText) {
            return 'Hoe u GeoFort kent: ' . $text;
        }

        return $this->row('Hoe u GeoFort kent', $text);
    }

    private function getDiscoveryText(string $text): string
    {
        return $this->validator->getDiscoveryCustomText($text);
    }

    private function returnCJPRequested(BookingRequestData $request): bool
    {
        return trim($request->cjpPasGebruik) === 'ja';
    }

    private function cjpNameRowText(
        BookingRequestData $request,
        bool $plainText = false,
    ): string {
        if (!$this->returnCJPRequested($request)) {
            return '';
        }

        $name = $request->cjpContactpersoonNaam ?? '';

        if ($name === '') {
            return '';
        }

        if ($plainText) {
            return 'Gebruiker CJP-Pas: ' . $name;
        }

        return $this->row('Gebruiker CJP-Pas', $name);
    }

    private function cjpCardNumberRowText(
        BookingRequestData $request,
        bool $plainText = false,
    ): string {
        if (!$this->returnCJPRequested($request)) {
            return '';
        }

        $cardNumber = $request->cjpPasnummer ?? '';

        if ($cardNumber === '') {
            return '';
        }

        if ($plainText) {
            return 'CJP-Pasnummer: ' . $cardNumber;
        }

        return $this->row('CJP-Pasnummer', $cardNumber);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function overviewHeader(string $label): string
    {
        return '
            <tr>
                <td colspan="2" style="' . MailStyles::overviewHeaderCell() . '">
                    ' . $this->escape($label) . '
                </td>
            </tr>';
    }

    private function educationSelectionRows(BookingRequestData $request): string
{
    $rows = '';

    foreach ($request->educationSelection->selectedLevels as $levelKey) {
        $levelLabel = $this->getLevelLabel(
            $request->educationSelection->sector,
            $levelKey,
        );

        $groupLabels = [];

        foreach (
            $request->educationSelection->selectedGroupsByLevel[$levelKey] ?? []
            as $groupKey
        ) {
            $groupLabels[] = $this->getGroupLabel(
                $request->educationSelection->sector,
                $levelKey,
                $groupKey,
            );
        }

        $rows .= $this->row(
            'Groepsselectie - ' . $levelLabel,
            implode(', ', $groupLabels),
        );
    }

    return $rows;
}

    private function getLevelLabel(string $sector, string $levelKey): string
    {
        return BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['label']
            ?? $levelKey;
    }

    private function getGroupLabel(
        string $sector,
        string $levelKey,
        string $groupKey,
    ): string {
        return BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['groups'][$groupKey]
            ?? $groupKey;
    }
    
        /**
     * @return string[]
     */
    private function educationSelectionTextLines(BookingRequestData $request): array
    {
        $lines = [];

        foreach ($request->educationSelection->selectedLevels as $levelKey) {
            $levelLabel = $this->getLevelLabel(
                $request->educationSelection->sector,
                $levelKey,
            );

            $groupLabels = [];

            foreach (
                $request->educationSelection->selectedGroupsByLevel[$levelKey] ?? []
                as $groupKey
            ) {
                $groupLabels[] = $this->getGroupLabel(
                    $request->educationSelection->sector,
                    $levelKey,
                    $groupKey,
                );
            }

            $lines[] = 'Groepsselectie - ' . $levelLabel . ': ' . implode(', ', $groupLabels);
        }

        return $lines;
    }
}