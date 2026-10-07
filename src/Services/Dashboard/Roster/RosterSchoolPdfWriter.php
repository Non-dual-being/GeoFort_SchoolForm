<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use RuntimeException;

final class RosterSchoolPdfWriter implements RosterSchoolPdfRenderer
{
    public const TEMPLATE_VERSION = 'school-v2';
    private const WIDTH = 785.89;
    private const HEIGHT = 539.28;
    private const FONT_SIZE = 10.5;

    public function render(array $school): string
    {
        // Unique, private runtime directory OUTSIDE the repository/public tree. Never accepts a client path.
        $temp = realpath(sys_get_temp_dir());
        $root = realpath(dirname(__DIR__, 4));
        if ($temp === false || $root === false || !is_writable($temp)
            || str_starts_with(strtolower(str_replace('\\', '/', $temp)) . '/', strtolower(str_replace('\\', '/', $root)) . '/')) {
            throw new RuntimeException('PDF-temp moet buiten de applicatierepository staan.');
        }
        $work = $temp . '/geofort-school-pdf-' . bin2hex(random_bytes(16));
        if (!mkdir($work, 0700)) throw new RuntimeException('Private PDF-werkmap ontbreekt.');
        try {
            foreach (['resources', 'fonts', 'temp'] as $directory) {
                if (!mkdir($work . '/' . $directory, 0700)) throw new RuntimeException('Private PDF-werkmap ontbreekt.');
            }
            // One repository-owned PNG only. No caller-supplied path, SVG, HTML or resource URI.
            $logo = $work . '/resources/geofort_logo.png';
            if (!copy($root . '/public/assets/images/geofort_logo.png', $logo)) throw new RuntimeException('GeoFort-logo ontbreekt.');
            $options = new Options();
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setChroot([$work . '/resources']);
            $options->setAllowedProtocols(['file://' => ['rules' => [
                [$options, 'validateLocalUri'],
                static fn (string $uri): array => realpath(str_replace('file://', '', $uri)) === realpath($logo)
                    ? [true, null] : [false, 'Alleen het vaste GeoFort-logo is toegestaan.'],
            ]]]);
            $options->setTempDir($work . '/temp');
            $options->setFontDir($work . '/fonts');
            $options->setFontCache($work . '/fonts');
            $options->setLogOutputFile('');
            $options->setDefaultFont('DejaVu Sans');
            $options->setIsFontSubsettingEnabled(true);
            $options->setFontHeightRatio(1.0);
            $dompdf = new Dompdf($options);
            $dompdf->setBasePath($work . '/resources/');
            $dompdf->setPaper('A4', 'landscape');
            $pages = $this->pages($school, $dompdf->getFontMetrics());
            $dompdf->loadHtml($this->html($school, $pages, $dompdf->getFontMetrics()), 'UTF-8');
            $dompdf->render();
            // A renderer overflow must fail before status mutation, never produce a clipped attachment.
            if ($dompdf->getCanvas()->get_page_count() !== count($pages) + 1) throw new RuntimeException('PDF-pagina-indeling past niet.');
            $bytes = $dompdf->output();
            if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) throw new RuntimeException('Ongeldige PDF-uitvoer.');
            return $bytes;
        } finally {
            $this->removeWork($work);
        }
    }

    /** @return list<int> */
    public static function groupSets(int $count): array
    {
        return match ($count) {
            1, 2, 3, 4, 5, 6 => [$count],
            7 => [4, 3], 8 => [4, 4], 9 => [5, 4], 10 => [5, 5],
            default => throw new RuntimeException('Ongeldig aantal PDF-groepen.'),
        };
    }

    private function pages(array $school, FontMetrics $metrics): array
    {
        $font = $metrics->getFont('DejaVu Sans', 'normal');
        $bold = $metrics->getFont('DejaVu Sans', 'bold');
        $schoolLines = $this->wrap($school['school'], self::WIDTH - 70, $metrics, $font, 12);
        $programLines = $this->wrap($this->date($school['visitDate']) . ($school['program'] === '' ? '' : '  |  ' . $school['program']), self::WIDTH - 70, $metrics, $font, self::FONT_SIZE);
        $lineHeight = max($metrics->getFontHeight($font, self::FONT_SIZE), $metrics->getFontHeight($bold, self::FONT_SIZE));
        $headingHeight = max(56, $metrics->getFontHeight($bold, 20) + 3
            + count($schoolLines) * $metrics->getFontHeight($font, 12) + count($programLines) * $lineHeight + 7);
        $pages = []; $offset = 0;
        foreach (self::groupSets(count($school['groups'])) as $count) {
            $width = (self::WIDTH - 90) / $count;
            $headers = array_map(fn (string $label): array => $this->wrap($label, $width - 12, $metrics, $bold, self::FONT_SIZE), array_slice($school['groups'], $offset, $count));
            $headerHeight = max(1, ...array_map('count', $headers)) * $lineHeight + 8;
            $available = self::HEIGHT - $headingHeight - $headerHeight - 36;
            $base = ['schoolLines' => $schoolLines, 'programLines' => $programLines, 'headingHeight' => $headingHeight,
                'headers' => $headers, 'headerHeight' => $headerHeight, 'width' => $width, 'available' => $available];
            $page = $base + ['rows' => []]; $used = 0;
            foreach ($school['rows'] as $row) {
                $cells = array_slice($row['cells'], $offset, $count);
                $merge = $cells[0]['neutral'] && count(array_filter($cells, static fn (array $cell): bool => $cell === $cells[0])) === $count;
                $wrapped = [];
                foreach ($merge ? [$cells[0]] : $cells as $cell) {
                    $wrapped[] = $cell + ['lines' => $this->wrap($cell['label'], ($merge ? self::WIDTH - 90 : $width) - 12, $metrics, $cell['neutral'] ? $bold : $font, self::FONT_SIZE)];
                }
                $neutral = count(array_filter($cells, static fn (array $cell): bool => $cell['neutral'])) === $count;
                $height = max(1, ...array_map(static fn (array $c): int => count($c['lines']), $wrapped)) * $lineHeight + 4.65;
                if ($height > $available) throw new RuntimeException('Roostertekst past niet leesbaar op A4.');
                if ($used + $height > $available && $page['rows'] !== []) {
                    $pages[] = $this->fillPage($page); $page = $base + ['rows' => []]; $used = 0;
                }
                $page['rows'][] = ['start' => $row['start'], 'end' => $row['end'], 'merge' => $merge,
                    'neutral' => $neutral, 'height' => $height, 'cells' => $wrapped];
                $used += $height;
            }
            $pages[] = $this->fillPage($page);
            $offset += $count;
        }
        return $pages;
    }

    /** Let lessons occupy the printable page; neutral blocks stay more compact. */
    private function fillPage(array $page): array
    {
        $spare = max(0, $page['available'] - array_sum(array_column($page['rows'], 'height')));
        $weight = array_sum(array_map(static fn (array $row): int => $row['neutral'] ? 1 : 3, $page['rows']));
        foreach ($page['rows'] as &$row) $row['height'] += $spare * ($row['neutral'] ? 1 : 3) / max(1, $weight);
        unset($row);
        return $page;
    }

    /** Pre-wrap using the actual embedded font, including labels without spaces. */
    private function wrap(string $text, float $width, FontMetrics $metrics, string $font, float $size): array
    {
        $lines = []; $line = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $next = $line === '' ? $word : $line . ' ' . $word;
            if ($metrics->getTextWidth($next, $font, $size) <= $width) { $line = $next; continue; }
            if ($line !== '') { $lines[] = $line; $line = ''; }
            foreach (mb_str_split($word) as $character) {
                if ($metrics->getTextWidth($line . $character, $font, $size) > $width && $line !== '') { $lines[] = $line; $line = ''; }
                $line .= $character;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines === [] ? [''] : $lines;
    }

    private function html(array $school, array $pages, FontMetrics $metrics): string
    {
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Gepland lesrooster</title><style>
            @page { margin: 28pt; } body { margin:0; font-family:"DejaVu Sans"; font-size:10.5pt; color:#172536; }
            .page { page-break-before:always; } .page:first-child { page-break-before:auto; }
            .heading { position:relative; margin-bottom:12pt; border-bottom:1pt solid #CCD5DE; }
            .brand { position:absolute; left:0; top:0; width:31pt; padding:5pt 6pt; background:#293F55; }
            .brand img { width:31pt; height:38.46pt; } .heading-text { margin-left:62pt; }
            h1 { font-size:18pt; line-height:20pt; margin:0 0 3pt; }
            .school { font-size:12pt; line-height:12pt; margin:0; }
            .program { line-height:10.5pt; margin:0; color:#455565; }
            .page-number { font-size:10pt; line-height:12pt; text-align:right; margin:7pt 0 0; color:#455565; }
            .schedule { border-collapse:collapse; table-layout:auto; width:785.89pt; }
            .schedule th,.schedule td { border:0.65pt solid #455565; padding:2pt 5pt; line-height:10.5pt; text-align:center; vertical-align:middle; white-space:nowrap; }
            th { background:#293F55; color:#FFFFFF; font-weight:bold; } td.time { font-weight:bold; background:#F3F6F9; }
            .schedule tr.neutral-row td { background:#FFFFFF; }
            tr { page-break-inside:avoid; } .neutral { font-weight:bold; }
            .information { border-collapse:collapse; table-layout:auto; width:785.89pt; }
            .information td { vertical-align:top; padding:0; } .information .gap { width:20pt; }
            .info-block { border:0.65pt solid #CCD5DE; border-top:3pt solid #293F55; padding:13pt; }
            .info-block h2 { font-size:14pt; line-height:18pt; color:#293F55; margin:0 0 12pt; }
            .info-field { margin:0 0 11pt; } .info-field:last-child { margin-bottom:0; }
            .info-field strong { font-size:10.5pt; line-height:14pt; color:#455565; }
            .info-field p { font-size:10.5pt; line-height:14pt; margin:2pt 0 0; }
            </style></head><body>';
        $total = count($pages) + 1;
        foreach ($pages as $index => $page) {
            $html .= '<div class="page">' . $this->heading($page) . '<table class="schedule"><colgroup><col style="width:45pt"><col style="width:45pt">';
            foreach ($page['headers'] as $_) $html .= '<col style="width:' . $page['width'] . 'pt">';
            $html .= '</colgroup><thead><tr><th style="width:34.35pt;height:' . ($page['headerHeight'] - 4.65) . 'pt">Start</th><th style="width:34.35pt">Einde</th>';
            foreach ($page['headers'] as $header) $html .= '<th style="width:' . ($page['width'] - 10.65) . 'pt">' . $this->lines($header) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($page['rows'] as $row) {
                $html .= '<tr' . ($row['neutral'] ? ' class="neutral-row"' : '') . '><td class="time" style="height:' . ($row['height'] - 4.65) . 'pt">' . $this->escape($row['start']) . '</td><td class="time">' . $this->escape($row['end']) . '</td>';
                foreach ($row['cells'] as $cell) {
                    // Hex color was allowlisted by the projection, and checked again at the renderer boundary.
                    if (!preg_match('/^#[0-9A-F]{6}$/D', $cell['color'])) throw new RuntimeException('Ongeldige PDF-kleur.');
                    $html .= '<td' . ($row['merge'] ? ' colspan="' . count($page['headers']) . '"' : '')
                        . ($cell['neutral'] ? ' class="neutral"' : '') . ' style="background-color:' . $cell['color'] . ';color:' . $this->contrast($cell['color']) . '">'
                        . $this->lines($cell['lines']) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>' . $this->pageNumber($index + 1, $total) . '</div>';
        }
        $html .= '<div class="page">' . $this->heading($pages[0]) . '<table class="information"><tr>';
        foreach (['school' => 'School en contact', 'program' => 'Programma en leerlingen'] as $key => $title) {
            if ($key === 'program') $html .= '<td class="gap"></td>';
            $html .= '<td style="width:382.94pt"><div class="info-block"><h2>' . $title . '</h2>';
            foreach ($school['information'][$key] as $label => $value) {
                $html .= '<div class="info-field"><strong>' . $this->escape($label) . '</strong><p>'
                    . $this->lines($this->wrap($value === '' ? 'Niet vastgelegd' : $value, 354, $metrics, $metrics->getFont('DejaVu Sans', 'normal'), self::FONT_SIZE)) . '</p></div>';
            }
            $html .= '</div></td>';
        }
        return $html . '</tr></table>' . $this->pageNumber($total, $total) . '</div></body></html>';
    }

    private function heading(array $page): string
    {
        return '<div class="heading" style="height:' . $page['headingHeight'] . 'pt"><div class="brand"><img src="geofort_logo.png" alt="GeoFort"></div>'
            . '<div class="heading-text"><h1>Gepland lesrooster</h1><p class="school">' . $this->lines($page['schoolLines']) . '</p>'
            . '<p class="program">' . $this->lines($page['programLines']) . '</p></div></div>';
    }

    private function pageNumber(int $page, int $total): string
    {
        return '<p class="page-number">Pagina ' . $page . ' / ' . $total . '</p>';
    }

    private function contrast(string $color): string
    {
        $rgb = array_map(static function (string $hex): float { $v = hexdec($hex) / 255; return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, str_split(substr($color, 1), 2));
        $luminance = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        return $luminance > 0.179 ? '#000000' : '#FFFFFF';
    }

    private function lines(array $lines): string { return implode('<br>', array_map($this->escape(...), $lines)); }
    private function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function date(string $date): string { return implode('-', array_reverse(explode('-', $date))); }

    private function removeWork(string $path): void
    {
        // Only this request's freshly created directory, no symlink traversal.
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $path . '/' . $name;
            if (is_dir($child) && !is_link($child)) $this->removeWork($child);
            else @unlink($child);
        }
        @rmdir($path);
    }
}
