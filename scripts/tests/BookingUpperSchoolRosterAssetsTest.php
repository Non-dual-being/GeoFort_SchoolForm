<?php

declare(strict_types=1);

// These reviewed PNG hashes pair the raster exports with the verified PDF pages.
// The PDF check below also reads the active page content, including incremental
// updates, so swapped group counts and an unrelated activity fail independently.
$assets = $argv[1] ?? dirname(__DIR__, 2) . '/public/assets/booking/roosters';
$pngHashes = [
    3 => 'BE2830ADE8B9F59E69ACC7BA48BE762AA570CE28AAE91A9184A569BBA6988DCF',
    4 => 'E3CCE0D06233A1D153374A9B7BCE2D8B34116C2EEB171BC0B7B960EDB4AA6348',
    5 => 'F796561662330CCF2003A69AB4146E9E2C44BFABDC59DD1EA60E872BF26C29CA',
    6 => '8209B7EC617AB416DF022AAA5667E044FC3ECE8FD27FDDFF22023A821CA3A79E',
    7 => 'D9DCD2DD15A91D4DE865883CACC240D5CAC4C4B12A9930DFACDA1E80D7D6C885',
    8 => '7CCCA4AC46624A9703DF49A1C6EDA93EA499E3D81244DB5FBA90D34A5BDB953B',
    9 => 'FFF6350F39972251C59236BED720D3EA55193ED069899ECD8F1B9BA051EF612D',
    10 => '1B4397408AD65E10554DB3F7299922717332573B6B4468649462973EB2E27074',
];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A deliberately narrow reader for these existing Excel PDF exports, not a
// general PDF parser. Unsupported structures fail instead of silently passing.
$pageLabels = static function (string $path) use ($assert): array {
    $bytes = file_get_contents($path);
    $assert(is_string($bytes) && str_starts_with($bytes, '%PDF-'), "Invalid PDF: {$path}");
    preg_match_all('/(\d+) 0 obj\s*([\s\S]*?)endobj/', $bytes, $matches, PREG_SET_ORDER);
    $objects = [];
    foreach ($matches as $match) $objects[(int) $match[1]] = $match[2];
    $pages = array_values(array_filter($objects, static fn(string $object): bool => preg_match('~/Type\s*/Page\b~', $object) === 1));
    $assert(count($pages) === 1, "Expected exactly one PDF page: {$path}");
    $assert(preg_match('~/Contents\s+(\d+) 0 R~', $pages[0], $reference) === 1, 'Unsupported PDF page contents');
    $stream = $objects[(int) $reference[1]] ?? '';
    $assert(str_contains($stream, '/FlateDecode') && preg_match('/stream\r?\n([\s\S]*?)\r?\nendstream/', $stream, $encoded) === 1, 'Unsupported PDF stream');
    $content = gzuncompress($encoded[1]);
    $assert(is_string($content), 'Could not decode PDF page');
    preg_match_all('/\[([^\]]*)\]\s*TJ/', $content, $textArrays);
    $labels = [];
    foreach ($textArrays[1] as $textArray) {
        preg_match_all('/\(([^()]*)\)/', $textArray, $fragments);
        $label = trim(implode('', $fragments[1]));
        if ($label !== '') $labels[] = $label;
    }
    $assert(count($labels) > 20, 'Expected readable text in the PDF page');
    return $labels;
};

$expectedModules = ['klimaatexperience', 'voedselinnovatie', 'dynamischeglobe', 'earthwatch', 'stopdeklimaatklok'];
foreach ($pngHashes as $groups => $hash) {
    $stem = "vo_boven_sdk_{$groups}";
    $labels = $pageLabels("{$assets}/pdf/{$stem}.pdf");
    $groupLabels = [];
    $moduleCounts = array_fill_keys($expectedModules, 0);
    foreach ($labels as $label) {
        if (preg_match('/^groep\s*(\d+)$/i', $label, $group)) $groupLabels[] = (int) $group[1];
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z]/', '', $label));
        $assert($normalized !== 'minecraftprogrammeren', "Unrelated Minecraft activity in {$stem}");
        if (array_key_exists($normalized, $moduleCounts)) $moduleCounts[$normalized]++;
    }
    sort($groupLabels);
    $assert($groupLabels === range(1, $groups), "PDF group headers do not match {$stem}");
    foreach ($moduleCounts as $module => $count) $assert($count === $groups, "Expected {$groups} occurrences of {$module} in {$stem}, got {$count}");
    $png = "{$assets}/images/{$stem}.png";
    $assert(strtoupper((string) hash_file('sha256', $png)) === $hash, "PNG does not match the reviewed {$groups}-group PDF export: {$stem}");
    $image = getimagesize($png);
    $assert(is_array($image) && $image[2] === IMAGETYPE_PNG && $image[0] > 1000 && $image[1] > 300, "Invalid PNG: {$stem}");
}

fwrite(STDOUT, "OK: eight upper-school climate-clock PDF/PNG pairs, groups 3–10, five modules per group in aggregate, and reviewed raster exports. No database or mail.\n");
