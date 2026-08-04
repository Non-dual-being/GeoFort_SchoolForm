<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$files = ['resources/js/admin/views/DashboardOverviewView.vue','resources/js/admin/components/dashboard/AdminIdentityCard.vue','resources/js/admin/components/dashboard/DashboardOptionListCard.vue','resources/js/admin/components/dashboard/DashboardNextOptionCard.vue','resources/js/admin/components/dashboard/DashboardMonthCard.vue','resources/js/admin/services/dashboardOverviewApi.ts','resources/js/admin/types/dashboardOverview.ts','resources/css/admin/cards.css','resources/css/admin/layout.css','resources/css/admin/responsive.css'];
$content = '';
foreach ($files as $file) { if (!is_file($root . '/' . $file)) { fwrite(STDERR, "FAIL: {$file} ontbreekt.\n"); exit(1); } $content .= file_get_contents($root . '/' . $file); }
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
foreach (['Aanvragen in optie','Eerstvolgende optie','Deze maand','Verlopen optie','Bekijk alle in optie','Open aanvraag','Bekijk in agenda','Open publieke reserveringspagina','/api/admin/overview.php','status: \'In optie\'','grid-template-columns','Opnieuw proberen'] as $needle) $assert(str_contains($content, $needle), "Frontendcontract mist {$needle}.");
$controls = file_get_contents($root . '/resources/css/admin/controls.css');
$assert(str_contains($content, 'admin-overview-page') && str_contains($controls, '.admin-overview-page .admin-button:hover:not(:disabled)') && str_contains($controls, 'transform: none;'), 'Overviewknoppen blijven niet stabiel bij interactie.');
$assert(!is_file($root . '/resources/js/admin/components/dashboard/FutureFeaturesCard.vue'), 'Tijdelijke toekomstkaart bestaat nog.');
exit(0);
