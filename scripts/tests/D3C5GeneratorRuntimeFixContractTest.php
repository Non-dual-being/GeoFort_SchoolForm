<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$generator = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterAutoGenerator.php',
);
$panel = (string) file_get_contents(
    $root . '/resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains(
        $generator,
        '\'templateNote\' => $this->templates->note($context[\'program\']),'
    ),
    'Generator templateNote gebruikt niet de operationele program-context.',
);

$assert(
    !str_contains(
        $generator,
        '\'templateNote\' => $this->templates->note($booking->program),'
    ),
    'Generator templateNote gebruikt nog de stale booking-variabele.',
);

foreach ([
    '() => [props.plan.id, props.plan.revision] as const',
    'function generationPreviewErrorMessage(requestError: unknown): string',
    'De generator kreeg geen geldig antwoord van de server.',
    'error.value = generationPreviewErrorMessage(requestError);',
] as $needle) {
    $assert(
        str_contains($panel, $needle),
        'Generator frontend runtime contract mist: ' . $needle,
    );
}

$assert(
    !str_contains(
        $panel,
        'watch(() => props.plan.id, resetWizard, { immediate: true });'
    ),
    'Wizard reset nog alleen op plan-id en kan daardoor stale rondes behouden.',
);

fwrite(STDOUT, "OK: D3C5 generator runtime fix contract passed.\n");