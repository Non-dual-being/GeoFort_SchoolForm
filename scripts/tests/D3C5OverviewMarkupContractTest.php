<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$overview = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRostersView.vue',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    '<th scope="col">Bron</th>',
    '<th scope="col">Type</th>',
    '<th scope="col">School</th>',
    '<td>{{ sourceLabel(item) }}</td>',
    '{{ planningModeLabel(item) }}',
    '<strong>{{ item.schoolName }}</strong>',
] as $needle) {
    $assert(
        str_contains($overview, $needle),
        'Overview markup contract mist: ' . $needle,
    );
}

$assert(
    preg_match('/^\s*\$[12]/m', $overview) !== 1,
    'DashboardRostersView bevat een letterlijke $1/$2 regex-placeholder.',
);

$bron = strpos($overview, '<th scope="col">Bron</th>');
$type = strpos($overview, '<th scope="col">Type</th>');
$school = strpos($overview, '<th scope="col">School</th>');

$assert(
    $bron !== false
    && $type !== false
    && $school !== false
    && $bron < $type
    && $type < $school,
    'Overview kolomvolgorde Bron -> Type -> School is beschadigd.',
);

fwrite(STDOUT, "OK: D3C5 overview markup contract passed.\n");