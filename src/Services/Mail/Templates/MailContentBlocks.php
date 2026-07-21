<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

final readonly class MailContentBlocks
{
    public function __construct(
        private MailLinks $links,
    ) {}

    public function visitDateHighlightHtml(string $visitDateLabel): string
    {
        $safeDate = $this->escape($visitDateLabel);

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="'
            . MailStyles::dateHighlightTable()
            . '">'
            . '<tr>'
            . '<td style="' . MailStyles::dateHighlightCell() . '">'
            . '<span style="' . MailStyles::dateHighlightLabel() . '">'
            . 'Aangevraagde bezoekdatum'
            . '</span>'
            . '<span style="' . MailStyles::dateHighlightValue() . '">'
            . $safeDate
            . '</span>'
            . '</td>'
            . '</tr>'
            . '</table>';
    }

    public function visitDateHighlightText(string $visitDateLabel): string
    {
        return "Aangevraagde bezoekdatum\n{$visitDateLabel}";
    }

    public function educationalOfferHtml(): string
    {
        $items = $this->educationalOfferItems();

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="'
            . MailStyles::resourceTable()
            . '">';

        $html .= '<tr>'
            . '<td colspan="2" style="' . MailStyles::resourceHeaderCell() . '">'
            . '<div style="' . MailStyles::resourceHeaderTitle() . '">'
            . 'Ontdek meer over ons educatief aanbod'
            . '</div>'
            . '<div style="' . MailStyles::resourceHeaderDescription() . '">'
            . 'Bekijk praktische informatie en ontdek ons aanbod voor in en buiten de klas.'
            . '</div>'
            . '</td>'
            . '</tr>';

        foreach ($items as $item) {
            $html .= '<tr>'
                . '<td width="36%" style="' . MailStyles::resourceLinkCell() . '">'
                . '<a href="' . $this->escapeAttr($item['url']) . '" style="' . MailStyles::link() . '">'
                . $this->escape($item['title'])
                . '</a>'
                . '</td>'
                . '<td width="64%" style="' . MailStyles::resourceDescriptionCell() . '">'
                . $this->escape($item['description'])
                . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    public function educationalOfferText(): string
    {
        $sections = [
            'Ontdek meer over ons educatief aanbod',
            'Bekijk praktische informatie en ontdek ons aanbod voor in en buiten de klas.',
        ];

        foreach ($this->educationalOfferItems() as $item) {
            $sections[] = $item['title']
                . "\n"
                . $item['description']
                . "\n"
                . $item['url'];
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<array{
     *     title: string,
     *     description: string,
     *     url: string
     * }>
     */
    private function educationalOfferItems(): array
    {
        return [
            [
                'title' => 'Afspraken en kosten schoolbezoek',
                'description' => 'Bekijk de voorwaarden en algemene afspraken rondom een schoolbezoek.',
                'url' => $this->links->voorwaardenUrl,
            ],
            [
                'title' => 'Online boekingsformulier',
                'description' => 'Dien een nieuwe aanvraag in zodra jullie een geschikte datum hebben gevonden.',
                'url' => $this->links->bookingFormUrl(),
            ],
            [
                'title' => 'GeoFort-lesmodules',
                'description' => 'Bekijk het educatieve aanbod en de lesmodules van GeoFort.',
                'url' => $this->links->geoFortLessonModulesUrl,
            ],
            [
                'title' => 'GoGeo online lesmodules',
                'description' => 'Ontdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.',
                'url' => $this->links->goGeoLessonModulesUrl,
            ],
            [
                'title' => 'Minecraft in de klas',
                'description' => 'Boek een interactieve Minecraft-workshop bij jullie op school of op locatie.',
                'url' => $this->links->minecraftWorkshopsUrl,
            ],
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
