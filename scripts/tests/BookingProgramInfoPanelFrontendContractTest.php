<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$panel = (string) file_get_contents(
    $root . '/resources/js/components/form/GeoFormBookingProgramInfoPanel.vue',
);
$styles = (string) file_get_contents(
    $root . '/resources/css/components/form/info-panel.css',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach ([
    'const openSectionIds = ref<Set<InfoSectionId>>(new Set())',
    'const allSectionIds: InfoSectionId[]',
    'const areAllSectionsOpen = computed',
    'allSectionIds.every((id) => openSectionIds.value.has(id))',
    'function toggleSection',
    'nextOpenSectionIds.delete(id)',
    'nextOpenSectionIds.add(id)',
    'function toggleAllSections',
    'new Set(allSectionIds)',
    '@click="toggleAllSections"',
    'Alles uitklappen',
    'Alles inklappen',
    'type="button"',
    ':aria-expanded="isSectionOpen(',
    ':aria-controls="sectionPanelId(',
    ':id="sectionPanelId(',
    'v-show="isSectionOpen(',
    'sectionHeadingId',
    'Dag- en ochtendprogramma',
    'Standaard- en keuzemodules',
    'Vegetarische snack · gratis begeleider · koffie/thee',
    'Cultuurkaart · allergieën · Museumjaarkaart',
    '"Sluiten"',
    '"Bekijken"',
    'ChevronDown',
] as $needle) {
    $assert(str_contains($panel, $needle), "Informatieaccordion mist {$needle}.");
}

$assert(
    !str_contains($panel, 'v-if="isSectionOpen('),
    'De kaartinhoud wordt bij inklappen opnieuw aangemaakt.',
);

$assert(
    substr_count($panel, 'class="booking-info-section-card__action"') === 4,
    'Niet iedere hoofdkaart heeft precies één actieknop.',
);

$assert(
    !str_contains($panel, 'booking-info-section-card__toggle'),
    'De volledige kaartkop is nog als knop gemarkeerd.',
);

$assert(
    !str_contains($panel, 'aria-pressed'),
    'De algemene actieknop mag geen aria-pressed gebruiken.',
);

foreach ([
    'align-items: stretch',
    '.booking-info-section-card__action:focus-visible',
    '.booking-info-section-card__action:hover',
    '.booking-info-card__toggle-all:focus-visible',
    'background: var(--color-main-blue-dark)',
    'grid-template-columns: minmax(0, 1fr) 6.75rem',
    'min-height: 9rem',
    'grid-template-rows: subgrid',
    'grid-auto-rows: 1fr',
    'height: 100%',
    'prefers-reduced-motion: reduce',
    '@media (max-width: 900px)',
] as $needle) {
    $assert(str_contains($styles, $needle), "Informatieaccordion-CSS mist {$needle}.");
}

$assert(
    !str_contains($styles, '.booking-info-section-card:hover'),
    'De volledige hoofdkaart heeft nog een klikachtige hoverstatus.',
);

fwrite(STDOUT, "OK: publieke informatiekaarten interactiecontract geslaagd.\n");
