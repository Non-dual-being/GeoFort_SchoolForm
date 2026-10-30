<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Mail\Templates\{MailLayout, MailLinks, VisitSurveyMailTemplate};

$template = new VisitSurveyMailTemplate(new MailLayout(new MailLinks('https://example.test', 'https://example.test/voorwaarden', 'onderwijs@example.test')));
$url = 'https://example.test/evaluatie/#token=' . str_repeat('a', 64);
$html = $template->html('  Éva & Anne  ', '2026-10-27', $url);
$text = $template->text('  Éva & Anne  ', '2026-10-27', $url);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($html, 'Beste Éva &amp; Anne,') && str_starts_with($text, 'Beste Éva & Anne,'), 'Contact name not trimmed/personalized/escaped');
$assert(str_contains($html, 'dinsdag 27 oktober 2026') && str_contains($text, 'dinsdag 27 oktober 2026'), 'Dutch visit date differs between versions');
$unsafe = $template->html('<img src=x onerror=alert(1)>', '2026-10-27', $url);
$assert(!str_contains($unsafe, '<img') && str_contains($unsafe, '&lt;img'), 'Contact name became HTML');
$assert(str_contains($template->html(' ', '2026-10-27', $url), 'Beste bezoeker,') && str_starts_with($template->text('', '2026-10-27', $url), 'Beste bezoeker,'), 'Missing name produces broken greeting');
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($previous);
$xpath = new DOMXPath($dom);
$buttons = $xpath->query('//a[normalize-space(.)="Deel jullie ervaring"]');
$assert($buttons->length === 1 && $buttons->item(0)->getAttribute('href') === $url, 'Invitation token lost in primary button');
$withdraw = $xpath->query('//a[normalize-space(.)="je toestemming intrekken"]');
$assert($withdraw->length === 1 && $withdraw->item(0)->getAttribute('href') === $url, 'Withdrawal link lost');
foreach (['vrijwillig', '30 dagen', 'geen namen', 'eerder antwoord verwijderen'] as $notice) {
    $assert(str_contains($html, $notice) && str_contains($text, $notice), 'Missing information in mail version');
}
$assert(str_contains($html, 'mailto:onderwijs@example.test') && str_contains($html, 'Met vriendelijke groet'), 'Shared layout footer lost');
echo "PASS survey mail: personal greeting, safe names, fallback, Dutch date, invitation/withdrawal links and text version; no mail sent\n";
