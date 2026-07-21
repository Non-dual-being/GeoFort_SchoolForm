<?php

declare(strict_types=1);

use GeoFort\Services\Mail\Templates\MailContentBlocks;
use GeoFort\Services\Mail\Templates\MailLinks;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$links = new MailLinks(
    baseUrl: 'https://formulier.test/base',
    voorwaardenUrl: 'https://formulier.test/voorwaarden',
    onderwijsEmail: 'onderwijs+test@geofort.nl',
);
$blocks = new MailContentBlocks($links);
$html = $blocks->visitDateHighlightHtml('23 september <2026>');
$text = $blocks->visitDateHighlightText('23 september 2026');
$offerHtml = $blocks->educationalOfferHtml();
$offerText = $blocks->educationalOfferText();
$combinedOffer = $offerHtml . "\n" . $offerText;

$assert(str_contains($html, 'Aangevraagde bezoekdatum'), 'Datumlabel ontbreekt.');
$assert(str_contains($html, '23 september &lt;2026&gt;'), 'HTML-datum wordt niet veilig escaped.');
$assert(!str_contains($html, '<2026>'), 'Onveilige HTML-datum staat in output.');
$assert($text === "Aangevraagde bezoekdatum\n23 september 2026", 'Tekstdatumstructuur wijkt af.');

foreach ([
    $links->voorwaardenUrl,
    $links->bookingFormUrl(),
    $links->geoFortLessonModulesUrl,
    $links->goGeoLessonModulesUrl,
    $links->minecraftWorkshopsUrl,
] as $url) {
    $assert(str_contains($combinedOffer, $url), "Gecentraliseerde URL ontbreekt: {$url}");
}

$assert($links->onderwijsMailtoUrl() === 'mailto:onderwijs+test@geofort.nl', 'Mailto-URL wijkt af.');
$assert(substr_count($offerHtml, 'width="36%"') === 5, 'Educatief blok bevat niet vijf linkrijen.');
$assert(str_contains($offerText, "GeoFort-lesmodules\nBekijk het educatieve aanbod"), 'Tekststructuur educatief blok wijkt af.');

fwrite(STDOUT, "OK: gedeelde mailcontentblokken en gecentraliseerde links gecontroleerd.\n");
