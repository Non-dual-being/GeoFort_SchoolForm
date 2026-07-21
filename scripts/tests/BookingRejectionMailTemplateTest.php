<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Mail\Templates\BookingRejectionMailTemplate;
use GeoFort\Services\Mail\Templates\MailContentBlocks;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\MailLinks;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @throws RuntimeException
 */
$assert = static function (
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$links = new MailLinks(
    baseUrl: 'https://onderwijsformulier.test',
    voorwaardenUrl: 'https://onderwijsformulier.test/booking/voorwaarden.php',
    onderwijsEmail: 'onderwijs@geofort.nl',
);

$layout = new MailLayout($links);
$contentBlocks = new MailContentBlocks($links);

$request = new BookingRequestData(
    'De Testschool',
    'Nederland',
    'Teststraat 1',
    '1234 AB',
    'Teststad',
    '0345123456',
    '0612345678',
    'Sanne',
    'Jansen',
    'sanne@example.test',
    '2026-09-23',
    'woensdag 23 september 2026',
    'Via school',
    'nee',
    null,
    null,
    'primairOnderwijs',
    BookingPolicy::PROGRAM_DAY,
    null,
    30,
    3,
    new EducationSelectionData(
        'primairOnderwijs',
        ['regulier'],
        [
            'regulier' => ['groep5'],
        ],
    ),
    new FoodAndDrinkSelectionData(
        0,
        0,
        0,
        0,
        0,
        'eigenPicknick',
        0,
        true,
    ),
    null,
    true,
);

$rejectionTemplate = new BookingRejectionMailTemplate(
    layout: $layout,
    links: $links,
    contentBlocks: $contentBlocks,
);

$subject = $rejectionTemplate->subject($request);
$html = $rejectionTemplate->html($request);
$text = $rejectionTemplate->text($request);
$combined = $html . "\n" . $text;

/*
|--------------------------------------------------------------------------
| Onderwerp en personalisatie
|--------------------------------------------------------------------------
*/

$assert(
    $subject === 'Helaas is de gekozen datum voor jullie schoolbezoek niet beschikbaar',
    'Onderwerpregel wijkt af.',
);

$assert(
    str_contains($combined, 'Beste Sanne,'),
    'Voornaam ontbreekt.',
);

$assert(
    str_contains($combined, 'woensdag 23 september 2026'),
    'Bezoekdatum ontbreekt.',
);

/*
|--------------------------------------------------------------------------
| Datumhighlight
|--------------------------------------------------------------------------
*/

$assert(
    str_contains($html, 'Aangevraagde bezoekdatum'),
    'Datumhighlight bevat geen label.',
);

$assert(
    str_contains($html, 'border-radius:8px'),
    'Datumhighlight bevat niet de verwachte afgeronde vormgeving.',
);

$assert(
    str_contains($html, 'woensdag 23 september 2026'),
    'Datumhighlight bevat niet de actuele bezoekdatum.',
);

$assert(
    str_contains(
        $text,
        "Aangevraagde bezoekdatum\nwoensdag 23 september 2026",
    ),
    'Platte tekst bevat geen herkenbare bezoekdatumsectie.',
);

/*
|--------------------------------------------------------------------------
| Afwijzingsboodschap en contactmogelijkheid
|--------------------------------------------------------------------------
*/

$assert(
    str_contains($combined, 'gekozen datum niet beschikbaar')
        || str_contains($combined, 'deze datum niet beschikbaar'),
    'Kernboodschap ontbreekt.',
);

$assert(
    str_contains($combined, 'alternatieve datum'),
    'Uitnodiging voor een alternatieve datum ontbreekt.',
);

$assert(
    str_contains($combined, 'contact met ons op'),
    'Contactlinktekst ontbreekt.',
);

$assert(
    str_contains(
        $combined,
        $links->onderwijsMailtoUrl(),
    ),
    'Mailto-link naar het onderwijsteam ontbreekt.',
);

$assert(
    !str_contains($combined, 'Heb je nog vragen?'),
    'Contactinformatie wordt dubbel herhaald.',
);

/*
|--------------------------------------------------------------------------
| Informatiepaneel
|--------------------------------------------------------------------------
*/

$assert(
    substr_count(
        $combined,
        'Ontdek meer over ons educatief aanbod',
    ) === 2,
    'Sectiekop ontbreekt in HTML of platte tekst.',
);

$assert(
    str_contains($html, 'colspan="2"'),
    'Herkenbare tweekolomsstructuur ontbreekt in de HTML.',
);

$assert(
    substr_count($html, 'width="36%"') === 5,
    'Informatiepaneel bevat niet exact vijf linkrijen.',
);

$expectedResources = [
    [
        'title' => 'Afspraken en kosten schoolbezoek',
        'description' => 'Bekijk de voorwaarden en algemene afspraken rondom een schoolbezoek.',
        'url' => $links->voorwaardenUrl,
    ],
    [
        'title' => 'Online boekingsformulier',
        'description' => 'Dien een nieuwe aanvraag in zodra jullie een geschikte datum hebben gevonden.',
        'url' => $links->bookingFormUrl(),
    ],
    [
        'title' => 'GeoFort-lesmodules',
        'description' => 'Bekijk het educatieve aanbod en de lesmodules van GeoFort.',
        'url' => $links->geoFortLessonModulesUrl,
    ],
    [
        'title' => 'GoGeo online lesmodules',
        'description' => 'Ontdek gratis online aardrijkskundelessen en lesmateriaal voor in de klas.',
        'url' => $links->goGeoLessonModulesUrl,
    ],
    [
        'title' => 'Minecraft in de klas',
        'description' => 'Boek een interactieve Minecraft-workshop bij jullie op school of op locatie.',
        'url' => $links->minecraftWorkshopsUrl,
    ],
];

foreach ($expectedResources as $resource) {
    $assert(
        str_contains($combined, $resource['title']),
        "Linktitel ontbreekt: {$resource['title']}",
    );

    $assert(
        str_contains($combined, $resource['description']),
        "Beschrijving ontbreekt: {$resource['title']}",
    );

    $assert(
        str_contains($combined, $resource['url']),
        "URL ontbreekt: {$resource['url']}",
    );

    $assert(
        str_contains(
            $text,
            $resource['title']
                . "\n"
                . $resource['description']
                . "\n"
                . $resource['url'],
        ),
        "Platte tekst mist titel, beschrijving of URL: {$resource['title']}",
    );
}

/*
|--------------------------------------------------------------------------
| Geen prijs of definitieve bevestigingsinhoud
|--------------------------------------------------------------------------
*/

$lowerCombined = strtolower($combined);

$assert(
    !str_contains($lowerCombined, 'totaalprijs'),
    'Afwijzingsmail bevat een totaalprijs.',
);

$assert(
    !str_contains($lowerCombined, 'prijsberekening'),
    'Afwijzingsmail bevat prijsberekeningsinformatie.',
);

$assert(
    !str_contains(
        $combined,
        'Uw schoolbezoek aan GeoFort is definitief bevestigd',
    ),
    'Afwijzingsmail bevat definitieve bevestigingstekst.',
);

$assert(
    !str_contains($lowerCombined, 'afgewezen'),
    'Afwijzingsmail zegt dat de school of aanvraag is afgewezen.',
);

fwrite(
    STDOUT,
    "OK: afwijzingsmail, datumhighlight, contactblok en informatielinks gecontroleerd.\n",
);
