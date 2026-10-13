<?php

declare(strict_types=1);

// Offline asset build; no database, booking changes or mail. Poppler is a build tool only.
require dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Booking\Roster\FourModuleExampleRoster;

$poppler = $argv[1] ?? '';
if (!is_file($poppler)) throw new RuntimeException('Geef het pad naar pdftoppm op.');
$base = dirname(__DIR__) . '/public/assets/booking/roosters';
$escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$count = 0;
foreach (['primairOnderwijs' => 'Primair onderwijs', 'voortgezetOnderbouw' => 'Voortgezet onderwijs'] as $sector => $label) {
    foreach (FourModuleProgram::choiceModules($sector, ['groep7', 'groep8']) as $choice) {
        for ($groups = 1; $groups <= 8; $groups++) {
            $stem = FourModuleExampleRoster::stem($sector, $choice, $groups);
            $schedule = FourModuleExampleRoster::schedule($sector, $choice, $groups);
            $html = '<!doctype html><html lang="nl"><meta charset="utf-8"><style>
                @page { margin: 25pt 24pt; } body { font-family: DejaVu Sans; color:#122A50; font-size:10pt; }
                .brand { font-size:12pt; font-weight:bold; letter-spacing:2pt; } h1 { font-size:23pt; margin:8pt 0 4pt; }
                .meta { margin:0 0 15pt; } table { border-collapse:collapse; width:100%; table-layout:fixed; border:1.5pt solid #122A50; }
                th,td { border:0.6pt solid #718096; padding:5pt 3pt; text-align:center; }
                th { background:#122A50; color:white; font-size:9pt; height:22pt; }
                td { color:#17263C; font-size:' . ($groups > 6 ? '8.5' : '10') . 'pt; }
                .lesson td { height:49pt; } .time { width:72pt; border-right:1.5pt solid #122A50; }
                .neutral td { height:22pt; background:white; } .note { margin-top:12pt; font-size:9pt; line-height:1.5; }
                </style><body><div class="brand">GEOfort · ONDERWIJS</div><h1>Voorbeeldrooster · vier modules</h1>
                <p class="meta">' . $label . ' &nbsp; | &nbsp; 10:00-14:00 &nbsp; | &nbsp; ' . $groups . ' '
                . ($groups === 1 ? 'groep' : 'groepen') . '</p><table><thead><tr><th class="time">Tijd</th>';
            for ($g = 1; $g <= $groups; $g++) $html .= '<th>Groep ' . $g . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($schedule['rows'] as $row) {
                $lesson = $row['kind'] === 'lesson';
                $html .= '<tr class="' . ($lesson ? 'lesson' : 'neutral') . '"><td class="time">'
                    . $row['start'] . '-' . $row['end'] . '</td>';
                if (!$lesson) $html .= '<td colspan="' . $groups . '">' . $escape($row['cells'][0]['label']) . '</td>';
                else foreach ($row['cells'] as $cell) $html .= '<td style="background:' . $cell['color'] . '">'
                    . $escape($cell['label']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table><p class="note"><strong>Een indruk van uw bezoek</strong><br>'
                . 'Iedere groep volgt alle vier modules en heeft een eigen GeoFort-begeleider. '
                . 'De volgorde en groepsindeling kunnen veranderen. U ontvangt het definitieve rooster van onze planner.</p></body></html>';
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false,
                'isJavascriptEnabled' => false, 'isFontSubsettingEnabled' => true]);
            $pdf = new Dompdf($options);
            $pdf->setPaper('A4', 'landscape');
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();
            if ($pdf->getCanvas()->get_page_count() !== 1) throw new RuntimeException('Voorbeeld past niet op één pagina: ' . $stem);
            $pdfPath = $base . '/pdf/' . $stem . '.pdf';
            file_put_contents($pdfPath, $pdf->output());
            $pipes = [];
            $process = proc_open([$poppler, '-singlefile', '-r', '120', '-png', $pdfPath, $base . '/images/' . $stem],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('PNG-export starten mislukt.');
            fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException($stderr . $stdout);
            $count++;
        }
    }
}
echo "Built {$count} public four-module PDF/PNG pairs.\n";
