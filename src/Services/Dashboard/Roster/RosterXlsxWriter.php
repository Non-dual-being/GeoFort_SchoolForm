<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use RuntimeException;
use ZipArchive;

final class RosterXlsxWriter
{
    private const STYLE_DEFAULT = 0;
    private const STYLE_TITLE = 1;
    private const STYLE_INFO_LABEL = 2;
    private const STYLE_INFO_VALUE = 3;
    private const STYLE_SECTION = 4;
    private const STYLE_HEADER = 5;
    private const STYLE_NEUTRAL = 6;
    private const STYLE_DG = 7;
    private const STYLE_EW = 8;
    private const STYLE_SDK = 9;
    private const STYLE_KE = 10;
    private const STYLE_VI = 11;
    private const STYLE_GENERIC = 12;
    private const STYLE_TIME = 13;
    private const STYLE_MC_CLIMATE = 14;
    private const STYLE_MC_PROGRAMMING = 15;

    /** @param array<string,mixed> $plan */
    public function write(string $path, array $plan): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX-export is op deze server niet beschikbaar.');
        }

        $groups = $this->normalizeGroups($plan);
        $sessions = $this->normalizeSessions($plan);
        $blocks = $this->buildBlocks($plan, $sessions);
        $staff = $this->buildStaffColumns($plan, $sessions);
        $meta = $this->buildMetaRows($plan, count($groups));
        $sheets = [
            $this->buildSheet('Printrooster', $meta, $blocks, $groups, $staff, $sessions, true, true),
            $this->buildSheet('Dagrooster', $meta, $blocks, $groups, $staff, $sessions, true, false),
            $this->buildSheet('Docentrooster', $meta, $blocks, $groups, $staff, $sessions, false, true),
        ];
        $styles = $this->compileStyles($sheets);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSX-bestand kon niet worden aangemaakt.');
        }
        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
            $zip->addFromString('xl/styles.xml', $styles);
            foreach ($sheets as $index => $sheet) {
                $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->worksheet($sheet));
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('XLSX-bestand kon niet worden afgerond.');
            }
        }
    }

    /** @param array<string,mixed> $plan @return list<array{id:int,label:string}> */
    private function normalizeGroups(array $plan): array
    {
        $result = [];
        foreach (($plan['groups'] ?? []) as $index => $group) {
            $result[] = [
                'id' => (int) ($group['id'] ?? ($index + 1)),
                'label' => (string) ($group['label'] ?? ('Groep ' . ($index + 1))),
            ];
        }
        $positions = array_column($plan['groups'] ?? [], 'position', 'id');
        usort($result, static fn (array $a, array $b): int =>
            isset($positions[$a['id']], $positions[$b['id']]) && $positions[$a['id']] !== $positions[$b['id']]
                ? $positions[$a['id']] <=> $positions[$b['id']]
                : (strnatcasecmp($a['label'], $b['label']) ?: $a['id'] <=> $b['id']));
        return $result;
    }

    /** @param array<string,mixed> $plan @return array<string,array> */
    private function buildMetaRows(array $plan, int $groupCount): array
    {
        $visit = [
            ['Datum', (string) ($plan['visitDate'] ?? '')],
            ['Programma', (string) ($plan['education']['programLabel'] ?? '')],
            ['Sector', (string) ($plan['education']['sectorLabel'] ?? '')],
            ['Niveau(s)', implode(' · ', $plan['education']['selectionLabels'] ?? [])],
            ['Keuzemodule', (string) ($plan['education']['choiceModuleLabel'] ?? '')],
            ['Leerlingen', $this->scalar($plan['education']['studentCount'] ?? null)],
            ['Groepen', (string) $groupCount],
        ];
        $school = [
            ['School', (string) ($plan['school']['name'] ?? '')],
            ['Adres', (string) ($plan['school']['address'] ?? '')],
            ['Postcode & plaats', trim(($plan['school']['postalCode'] ?? '') . ' ' . ($plan['school']['city'] ?? ''))],
            ['Contactpersoon', (string) ($plan['school']['contactName'] ?? '')],
            ['E-mailadres', (string) ($plan['school']['contactEmail'] ?? '')],
            ['Schooltelefoon', (string) ($plan['school']['schoolPhone'] ?? '')],
            ['Telefoon contactpersoon', (string) ($plan['school']['contactPhone'] ?? '')],
        ];
        $country = trim((string) ($plan['school']['country'] ?? ''));
        if ($country !== '' && mb_strtolower($country, 'UTF-8') !== 'nederland') $school[] = ['Land', $country];
        $available = static fn (array $row): bool => trim($row[1]) !== '';
        return [
            'visit' => array_values(array_filter($visit, $available)),
            'school' => array_values(array_filter($school, $available)),
            'guides' => array_column($this->selectedStaff($plan), 'name'),
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,array{key:string,name:string,role:string}> */
    private function selectedStaff(array $plan): array
    {
        $selected = array_map('intval', $plan['planning']['selectedStaffIds'] ?? []);
        $staff = [];
        foreach (($plan['planning']['staffCatalog'] ?? []) as $member) {
            if ((int) $member['id'] > 0 && in_array((int) $member['id'], $selected, true)) {
                $staff += $this->staffMembers([$member]);
            }
        }
        uasort($staff, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));
        return $staff;
    }

    /** @param list<array<string,mixed>> $assignments @return array<string,array{key:string,name:string,role:string}> */
    private function staffMembers(array $assignments): array
    {
        $members = [];
        foreach ($assignments as $assignment) {
            $name = trim((string) ($assignment['name'] ?? ''));
            if ($name === '') continue;
            $key = isset($assignment['id']) ? 'staff:' . (int) $assignment['id'] : 'name:' . $name;
            $members[$key] = ['key' => $key, 'name' => $name, 'role' => 'GeoFort'];
        }
        return $members;
    }

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    private function normalizeSessions(array $plan): array
    {
        $groupNames = array_column($this->normalizeGroups($plan), 'label', 'id');
        $groupPositions = array_flip(array_keys($groupNames));
        $catalog = array_column($plan['planning']['staffCatalog'] ?? [], null, 'id');
        $cookId = (int) ($plan['planning']['staffingSettings']['cookStaffId'] ?? 0);
        $sessions = [];
        foreach (($plan['planning']['sessions'] ?? []) as $session) {
            $groupIds = array_map('intval', $session['groupIds'] ?? []);
            $groupLabels = [];
            foreach ($groupIds as $index => $id) {
                $groupLabels[] = $groupNames[$id] ?? $session['groupLabels'][$index] ?? ('Groep ' . $id);
            }
            if ($groupLabels === []) {
                $groupLabels = array_map('strval', $session['groupLabels'] ?? []);
            }
            $staff = $this->staffMembers($session['staffAssignments'] ?? []);
            $groupStaff = [];
            foreach (($session['groupStaffAssignments'] ?? []) as $assignment) {
                $groupStaff[(int) $assignment['groupId']] = $this->staffMembers($assignment['staffAssignments'] ?? []);
            }
            $kinds = array_column($session['groupSupervision'] ?? [], 'kind', 'groupId');
            foreach ($groupIds as $gid) {
                $kind = $kinds[$gid] ?? null;
                $assigned = $groupStaff[$gid] ?? $staff;
                if ($kind === 'school') $assigned = ['school' => ['key' => 'school', 'name' => 'School', 'role' => 'School']];
                elseif ($kind === 'open' || $assigned === []) $assigned = ['open' => ['key' => 'open', 'name' => 'OPEN', 'role' => 'Open']];
                $groupStaff[$gid] = $assigned;
            }
            $guides = $staff;
            foreach ($groupStaff as $assignments) $guides += $assignments;
            unset($guides['school'], $guides['open']);
            if (count($guides) < (int) ($session['minimumGeoFortStaff'] ?? 0)) {
                foreach ($groupStaff as &$assignments) {
                    if (!isset($assignments['school'])) $assignments['open'] = ['key' => 'open', 'name' => 'OPEN', 'role' => 'Open'];
                }
                unset($assignments);
            }
            $sessions[] = [
                'start' => (string) ($session['startTime'] ?? ''),
                'end' => (string) ($session['endTime'] ?? ''),
                'module' => (string) ($session['moduleLabel'] ?? ''),
                'moduleKey' => (string) ($session['moduleKey'] ?? $session['moduleLabel'] ?? ''),
                'groupIds' => $groupIds, 'groupLabels' => $groupLabels,
                'groupPositions' => $groupPositions,
                'staff' => $staff, 'groupStaff' => $groupStaff,
                // The existing cook setting is a display source, never a guide assignment.
                'cook' => ($session['moduleKey'] ?? null) === 'Voedsel-Innovatie' && isset($catalog[$cookId])
                    ? $this->staffMembers([$catalog[$cookId]]) : [],
                'schoolSupervisionAllowed' => (bool) ($session['schoolSupervisionAllowed'] ?? false),
                'minimumGeoFortStaff' => (int) ($session['minimumGeoFortStaff'] ?? 0),
            ];
        }
        return $sessions;
    }

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $sessions @return list<array<string,mixed>> */
    private function buildBlocks(array $plan, array $sessions): array
    {
        $map = [];
        // getPlan() supplies the complete selected day as rounds. timeline is legacy export input.
        $timeline = $plan['planning']['rounds'] ?? $plan['planning']['timeline'] ?? [];
        foreach ($timeline as $entry) {
            $start = (string) ($entry['startTime'] ?? '');
            $end = (string) ($entry['endTime'] ?? '');
            if ($start === '' || $end === '') continue;
            $label = (string) ($entry['label'] ?? $entry['moduleLabel'] ?? $entry['name'] ?? '');
            $map[$start . '|' . $end] = [
                'start' => $start, 'end' => $end, 'label' => $label,
                'kind' => $this->isNeutralActivity($label, $entry) ? 'activity' : 'lesson',
            ];
        }
        // Preserve existing sessions outside a saved time block; never synthesize breaks or times.
        foreach ($sessions as $session) {
            $key = $session['start'] . '|' . $session['end'];
            $map[$key] ??= [
                'start' => $session['start'], 'end' => $session['end'],
                'label' => $session['module'], 'kind' => 'lesson',
            ];
        }
        $blocks = array_values($map);
        usort($blocks, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        return $blocks;
    }

    /** @param array<string,mixed> $entry */
    private function isNeutralActivity(string $label, array $entry): bool
    {
        $kind = strtolower((string) ($entry['type'] ?? $entry['kind'] ?? $entry['category'] ?? ''));
        if (in_array($kind, ['round', 'lesson'], true)) return false;
        if (in_array($kind, ['activity', 'welcome', 'arrival', 'departure', 'break', 'pause', 'lunch'], true)) return true;
        // Only legacy input without a semantic type needs label recognition.
        if ($kind !== '') return false;
        foreach (['aankomst', 'welkom', 'pauze', 'lunch', 'afscheid', 'vertrek'] as $needle) {
            if (str_contains($this->normalizeLabel($label), $needle)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $session @return array<string,array{key:string,name:string,role:string}> */
    private function sessionStaff(array $session): array
    {
        $staff = $session['staff'];
        foreach ($session['groupStaff'] as $assignments) $staff += $assignments;
        if ($staff === [] || $session['minimumGeoFortStaff'] > count(array_filter($staff, static fn (array $member): bool => !in_array($member['key'], ['school', 'open'], true)))) {
            $staff['open'] = ['key' => 'open', 'name' => 'OPEN', 'role' => 'Open'];
        }
        return $staff;
    }

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $sessions @return list<array{key:string,name:string,role:string}> */
    private function buildStaffColumns(array $plan, array $sessions): array
    {
        // Selected teachers remain visible even in a free round or without lesson assignments.
        $staff = $this->selectedStaff($plan);
        foreach ($sessions as $session) $staff += $this->sessionStaff($session) + $session['cook'];
        $staff = array_values($staff);
        usort($staff, static fn (array $a, array $b): int =>
            [match ($a['key']) { 'school' => 1, 'open' => 2, default => 0 }, $a['name'], $a['key']]
            <=> [match ($b['key']) { 'school' => 1, 'open' => 2, default => 0 }, $b['name'], $b['key']],
        );
        return $staff;
    }

    /** @param array<string,array> $meta @param list<array<string,mixed>> $blocks @param list<array{id:int,label:string}> $groups @param list<array<string,string>> $staff @param list<array<string,mixed>> $sessions @return array<string,mixed> */
    private function buildSheet(string $name, array $meta, array $blocks, array $groups, array $staff, array $sessions, bool $day, bool $teachers): array
    {
        $width = max(12, $day ? count($groups) + 2 : 0, $teachers ? count($staff) + 2 : 0);
        $sheet = [
            'name' => $name, 'rows' => [], 'merges' => [], 'outlines' => [], 'repeatRows' => [],
            'widths' => array_merge([11.5, 11.5], array_fill(0, $width - 2, 24.0)),
        ];

        $zoneSize = intdiv($width, 3);
        $zones = [[1, $zoneSize], [$zoneSize + 1, 2 * $zoneSize], [2 * $zoneSize + 1, $width]];
        $headers = ['Bezoek & onderwijsprogramma', 'School- en contactgegevens', 'Begeleiding'];
        $infoHeight = max(count($meta['visit']), count($meta['school']), count($meta['guides'])) + 1;
        for ($index = 0; $index < $infoHeight; $index++) {
            $isHeader = $index === 0;
            $cells = array_fill(0, $width, $this->cell('', $isHeader ? self::STYLE_HEADER : self::STYLE_INFO_VALUE));
            $row = $index + 1;
            $height = $isHeader ? 24.0 : 21.0;
            foreach ($zones as $zone => [$left, $right]) {
                $ranges = $isHeader ? [[$left, $right, $headers[$zone], self::STYLE_HEADER]]
                    : ($zone === 2 ? [[$left, $right, $meta['guides'][$index - 1] ?? '', self::STYLE_INFO_VALUE]]
                        : [[$left, $left + 1, $meta[$zone === 0 ? 'visit' : 'school'][$index - 1][0] ?? '', self::STYLE_INFO_LABEL],
                            [$left + 2, $right, $meta[$zone === 0 ? 'visit' : 'school'][$index - 1][1] ?? '', self::STYLE_INFO_VALUE]]);
                foreach ($ranges as [$start, $end, $value, $style]) {
                    for ($column = $start; $column <= $end; $column++) {
                        $cells[$column - 1] = $this->cell($column === $start ? $value : '', $style);
                        if ($column > $start) $cells[$column - 1]['edges']['left'] = '';
                        if ($column < $end) $cells[$column - 1]['edges']['right'] = '';
                    }
                    if ($end > $start) $sheet['merges'][] = [$start, $row, $end, $row];
                    $height = max($height, $this->textHeight($value, array_sum(array_slice($sheet['widths'], $start - 1, $end - $start + 1))));
                }
            }
            $sheet['rows'][] = ['cells' => $cells, 'height' => $height];
        }
        foreach ($zones as [$left, $right]) $sheet['outlines'][] = [$left, 1, $right, $infoHeight];
        $sheet['rows'][] = ['cells' => [], 'height' => 12.0];
        if ($day) {
            $titleRow = $this->appendSchedule($sheet, 'Lesrooster', $blocks, $groups, $sessions, 'group');
            if (!$teachers) $sheet['repeatRows'] = [$titleRow, $titleRow + 1];
        }
        if ($day && $teachers) $sheet['rows'][] = ['cells' => [], 'height' => 18.0];
        if ($teachers) {
            $titleRow = $this->appendSchedule($sheet, 'Docentenrooster', $blocks, $staff, $sessions, 'staff');
            if (!$day) $sheet['repeatRows'] = [$titleRow, $titleRow + 1];
        }

        // Last styling pass, including every edge cell of merged titles.
        foreach ($sheet['outlines'] as [$left, $top, $right, $bottom]) {
            for ($row = $top; $row <= $bottom; $row++) {
                for ($column = $left; $column <= $right; $column++) {
                    $edges = &$sheet['rows'][$row - 1]['cells'][$column - 1]['edges'];
                    if ($row === $top) $edges['top'] = 'thick';
                    if ($row === $bottom) $edges['bottom'] = 'thick';
                    if ($column === $left) $edges['left'] = 'thick';
                    if ($column === $right) $edges['right'] = 'thick';
                    unset($edges);
                }
            }
        }
        // Also mirror the merged right edge on the anchor for spreadsheet readers.
        foreach ($sheet['merges'] as [$left, $top, $right, $bottom]) {
            $sheet['rows'][$top - 1]['cells'][$left - 1]['edges']['right']
                = $sheet['rows'][$bottom - 1]['cells'][$right - 1]['edges']['right'];
        }
        return $sheet;
    }

    /** @param array<string,mixed> $sheet @param list<array<string,mixed>> $blocks @param list<array<string,mixed>> $columns @param list<array<string,mixed>> $sessions */
    private function appendSchedule(array &$sheet, string $title, array $blocks, array $columns, array $sessions, string $mode): int
    {
        $width = count($columns) + 2;
        $top = count($sheet['rows']) + 1;
        $cells = array_fill(0, $width, $this->cell('', self::STYLE_SECTION));
        $cells[0]['value'] = $title;
        foreach ($cells as &$cell) {
            $cell['edges'] = ['left' => '', 'right' => '', 'top' => 'thin', 'bottom' => 'medium'];
        }
        unset($cell);
        $sheet['rows'][] = ['cells' => $cells, 'height' => 22.0];
        $sheet['merges'][] = [1, $top, $width, $top];
        $labels = array_merge(['Begintijd', 'Eindtijd'], array_column($columns, $mode === 'group' ? 'label' : 'name'));
        $cells = [];
        $height = 26.0;
        foreach ($labels as $index => $label) {
            $cell = $this->cell($label, self::STYLE_HEADER);
            $cell['edges'] = array_fill_keys(['left', 'right', 'top', 'bottom'], 'medium');
            $cells[] = $cell;
            $height = max($height, $this->textHeight($label, $sheet['widths'][$index]));
        }
        $sheet['rows'][] = ['cells' => $cells, 'height' => $height];
        foreach ($blocks as $block) {
            $neutral = $block['kind'] === 'activity';
            $cells = [
                $this->cell($block['start'], $neutral ? self::STYLE_NEUTRAL : self::STYLE_TIME),
                $this->cell($block['end'], $neutral ? self::STYLE_NEUTRAL : self::STYLE_TIME),
            ];
            foreach ($columns as $column) {
                if ($neutral) {
                    $cells[] = $this->cell($block['label'], self::STYLE_NEUTRAL);
                } elseif ($mode === 'group') {
                    $cells[] = $this->sessionCellForGroup($this->findSessionForGroup($column['id'], $block['start'], $block['end'], $sessions));
                } else {
                    $cells[] = $this->sessionCellForStaff($column['key'], $block['start'], $block['end'], $sessions);
                }
            }
            $cells[1]['edges']['right'] = 'medium';
            if ($width > 2) $cells[2]['edges']['left'] = 'medium';
            $height = $neutral ? 24.0 : 30.0;
            foreach ($cells as $index => $cell) $height = max($height, $this->textHeight($cell['value'], $sheet['widths'][$index]));
            $sheet['rows'][] = ['cells' => $cells, 'height' => $height];
        }
        $sheet['outlines'][] = [1, $top, $width, count($sheet['rows'])];
        return $top;
    }

    /** @param list<array<string,mixed>> $sessions */
    private function findSessionForGroup(int $groupId, string $start, string $end, array $sessions): ?array
    {
        foreach ($sessions as $session) {
            if ($session['start'] === $start && $session['end'] === $end && in_array($groupId, $session['groupIds'], true)) return $session;
        }
        return null;
    }

    /** @param ?array<string,mixed> $session @return array<string,mixed> */
    private function sessionCellForGroup(?array $session): array
    {
        return $session === null
            ? $this->cell('')
            : $this->cell($session['module'], $this->moduleStyle($session['module'], $session['moduleKey']));
    }

    /** @param list<array<string,mixed>> $sessions @return array<string,mixed> */
    private function sessionCellForStaff(string $key, string $start, string $end, array $sessions): array
    {
        $modules = [];
        $style = self::STYLE_DEFAULT;
        foreach ($sessions as $session) {
            if ($session['start'] !== $start || $session['end'] !== $end) continue;
            $isCook = isset($session['cook'][$key]);
            if (!$isCook && !isset($this->sessionStaff($session)[$key])) continue;
            $labels = [];
            if ($session['groupIds'] === []) {
                foreach ($session['groupLabels'] as $label) $labels['label:' . $label] = ['label' => $label, 'position' => null];
            } else {
                foreach ($session['groupIds'] as $index => $groupId) {
                    $assignments = $session['groupStaff'][$groupId] ?? $session['staff'];
                    if ($isCook || isset($assignments[$key])) $labels[$groupId] = [
                        'label' => $session['groupLabels'][$index], 'position' => $session['groupPositions'][$groupId] ?? null,
                    ];
                }
            }
            if ($labels === []) continue;
            $moduleKey = $session['moduleKey'];
            $modules[$moduleKey] ??= ['label' => $session['module'], 'cook' => false, 'groups' => []];
            $modules[$moduleKey]['cook'] = $modules[$moduleKey]['cook'] || $isCook;
            $modules[$moduleKey]['groups'] += $labels;
            $style = $this->moduleStyle($session['module'], $session['moduleKey']);
        }
        $chunks = [];
        foreach ($modules as $module) {
            $groups = array_values($module['groups']);
            usort($groups, static fn (array $a, array $b): int =>
                $a['position'] !== null && $b['position'] !== null && $a['position'] !== $b['position']
                    ? $a['position'] <=> $b['position'] : strnatcasecmp($a['label'], $b['label']));
            $chunks[] = ($module['cook'] ? 'Kok · ' : '') . $module['label'] . "\n"
                // Wrap between tokens; keep e.g. "Groep 3" together within its parentheses.
                . implode(' ', array_map(static fn (array $group): string => '(' . str_replace(' ', "\u{00A0}", $group['label']) . ')', $groups));
        }
        return $this->cell(implode("\n\n", $chunks), $style);
    }

    private function moduleStyle(string $label, string $moduleKey = ''): int
    {
        // Identity is the exact key. Older presentation-only input may contain the full label instead.
        if ($moduleKey === 'Minecraft-Klimaatspeurtocht' || $moduleKey === 'Minecraft Klimaatspeurtocht') return self::STYLE_MC_CLIMATE;
        if ($moduleKey === 'Minecraft-Programmeren' || $moduleKey === 'Minecraft Programmeren') return self::STYLE_MC_PROGRAMMING;
        $normalized = $this->normalizeLabel($label);
        if (str_contains($normalized, 'dynamische globe')) {
            return self::STYLE_DG;
        }
        if (str_contains($normalized, 'earth watch')) {
            return self::STYLE_EW;
        }
        if (str_contains($normalized, 'stop de klimaat klok') || str_contains($normalized, 'stop-de-klimaat-klok') || str_contains($normalized, 'stopdeklimaatklok')) {
            return self::STYLE_SDK;
        }
        if (str_contains($normalized, 'klimaat experience')) {
            return self::STYLE_KE;
        }
        if (str_contains($normalized, 'voedsel innovatie') || $normalized === 'vi') {
            return self::STYLE_VI;
        }

        return self::STYLE_GENERIC;
    }

    private function normalizeLabel(string $value): string
    {
        return strtolower(trim($value));
    }

    /** @return array{value:string,style:int,edges:array<string,string>} */
    private function cell(string $value, int $style = self::STYLE_DEFAULT): array
    {
        return ['value' => $value, 'style' => $style, 'edges' => array_fill_keys(['left', 'right', 'top', 'bottom'], 'thin')];
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function textHeight(string $value, float $width): float
    {
        // Conservative Calibri 10 estimate, including explicit lines and long unbroken names.
        $capacity = max(1, (int) floor($width));
        $lines = 0;
        foreach (explode("\n", $value) as $line) {
            $used = 0;
            $lines++;
            foreach (preg_split('/\s+/u', trim($line)) ?: [] as $word) {
                $length = mb_strlen($word, 'UTF-8');
                if ($used > 0 && $used + 1 + $length > $capacity) {
                    $lines++;
                    $used = 0;
                }
                if ($used > 0) $used++;
                $used += $length;
                while ($used > $capacity) {
                    $lines++;
                    $used -= $capacity;
                }
            }
        }
        return $lines * 12.0 + 6.0;
    }

    /** @param list<array<string,mixed>> $sheets */
    private function compileStyles(array &$sheets): string
    {
        $borders = [['left' => '', 'right' => '', 'top' => '', 'bottom' => '']];
        $borderIds = ['|||' => 0];
        $variants = [[self::STYLE_DEFAULT, 0]];
        $variantIds = ['0:0' => 0];
        foreach ($sheets as &$sheet) {
            foreach ($sheet['rows'] as &$row) {
                foreach ($row['cells'] as &$cell) {
                    $borderKey = implode('|', $cell['edges']);
                    if (!isset($borderIds[$borderKey])) {
                        $borderIds[$borderKey] = count($borders);
                        $borders[] = $cell['edges'];
                    }
                    $borderId = $borderIds[$borderKey];
                    $variantKey = $cell['style'] . ':' . $borderId;
                    if (!isset($variantIds[$variantKey])) {
                        $variantIds[$variantKey] = count($variants);
                        $variants[] = [$cell['style'], $borderId];
                    }
                    $cell['styleIndex'] = $variantIds[$variantKey];
                }
                unset($cell);
            }
            unset($row);
        }
        unset($sheet);
        // The established export module palette and its black text are preserved.
        $fills = ['FFFFFF', 'F1F3F5', 'F7E6D5', 'FFFFFF', 'F1F3F5', '737373', 'FFFFFF',
            'CFE2F3', 'D9EAD3', 'F4CCCC', 'FCE5CD', 'EADCF8', 'D9EAF7', 'F7E6D5', 'D9B5DB', 'C7C3EE'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="10"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="' . (count($fills) + 2) . '"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>';
        foreach ($fills as $color) {
            $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $xml .= '</fills><borders count="' . count($borders) . '">';
        foreach ($borders as $edges) {
            $xml .= '<border>';
            foreach ($edges as $side => $weight) {
                $xml .= $weight === '' ? '<' . $side . '/>'
                    : '<' . $side . ' style="' . $weight . '"><color rgb="FF000000"/></' . $side . '>';
            }
            $xml .= '<diagonal/></border>';
        }
        $xml .= '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($variants) . '">';
        foreach ($variants as [$style, $borderId]) {
            $fontId = $style === self::STYLE_HEADER ? 1 : 0;
            $xml .= '<xf numFmtId="0" fontId="' . $fontId . '" fillId="' . ($style + 2) . '" borderId="' . $borderId
                . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
                . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** @param array<string,mixed> $sheet */
    private function worksheet(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $this->columnName(count($sheet['widths'])) . count($sheet['rows']) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="21"/><cols>';
        foreach ($sheet['widths'] as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        foreach ($sheet['rows'] as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="' . $excelRow . '" ht="' . $row['height'] . '" customHeight="1">';
            foreach ($row['cells'] as $columnIndex => $cell) {
                $reference = $this->columnName($columnIndex + 1) . $excelRow;
                $xml .= '<c r="' . $reference . '" t="inlineStr" s="' . $cell['styleIndex'] . '">'
                    . '<is><t xml:space="preserve">' . $this->xml($cell['value']) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><mergeCells count="' . count($sheet['merges']) . '">';
        foreach ($sheet['merges'] as [$left, $top, $right, $bottom]) {
            $xml .= '<mergeCell ref="' . $this->columnName($left) . $top . ':' . $this->columnName($right) . $bottom . '"/>';
        }
        return $xml . '</mergeCells><printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.30" right="0.30" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/></worksheet>';
    }

    /** @param list<array<string,mixed>> $sheets */
    private function workbook(array $sheets): string
    {
        $sheetXml = '';
        $names = '';
        foreach ($sheets as $index => $sheet) {
            $id = $index + 1;
            $sheetXml .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $prefix = "'" . $sheet['name'] . "'!";
            $names .= '<definedName name="_xlnm.Print_Area" localSheetId="' . $index . '">'
                . $prefix . '$A$1:$' . $this->columnName(count($sheet['widths'])) . '$' . count($sheet['rows']) . '</definedName>';
            if ($sheet['repeatRows'] !== []) {
                $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $index . '">'
                    . $prefix . '$' . $sheet['repeatRows'][0] . ':$' . $sheet['repeatRows'][1] . '</definedName>';
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetXml . '</sheets><definedNames>' . $names . '</definedNames></workbook>';
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $index
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId' . $index . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $index . '.xml"/>';
        }
        $relationships .= '<Relationship Id="rId' . ($sheetCount + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relationships
            . '</Relationships>';
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
