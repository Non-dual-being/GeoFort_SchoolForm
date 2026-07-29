<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composable = (string) file_get_contents($root . '/resources/js/admin/composables/useDisclosure.ts');
$component = (string) file_get_contents($root . '/resources/js/admin/components/help/AdminCollapsibleHelp.vue');
$styles = (string) file_get_contents($root . '/resources/css/admin/help.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach (['defaultOpen', 'isOpen', 'contentId', 'toggle', 'open', 'close', 'useId'] as $needle) {
    $assert(str_contains($composable, $needle), "Disclosure-composable mist {$needle}.");
}
foreach (['type="button"', 'aria-expanded', 'aria-controls', ':id="contentId"', ':inert="!isOpen"', '@click="toggle"'] as $needle) {
    $assert(str_contains($component, $needle), "Helpcomponent mist {$needle}.");
}

$assert(str_contains($styles, 'prefers-reduced-motion: reduce'), 'Helpanimatie respecteert reduced motion niet.');
$assert(!str_contains($component, 'v-html'), 'Helpcomponent gebruikt onveilige HTML-rendering.');

exit(0);
