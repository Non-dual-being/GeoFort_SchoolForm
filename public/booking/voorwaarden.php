<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\ViteService;

require __DIR__ . '/../../vendor/autoload.php';

$container = require_once __DIR__ . '/../../bootstrap.php';

$config = BookingProgramConfig::forFrontend();
$policy = BookingPolicy::getBookingPolicyForFrontend();
$vite = new ViteService(
    envState: $container['config']['app_env'],
    buildPath: $container['config']['vite_build_path'],
    devServerUrl: $container['config']['vite_dev_server_url'],
);

$baseUrl = (string) ($container['config']['base_url'] ?? '');
$returnUrl = $baseUrl !== '' ? $baseUrl . '/' : '/';
$pdfUrl = '/assets/booking/documents/Algemene_Voorwaarden_GeoFort_Onderwijs.pdf';

function e(string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatEuro(float $amount): string
{
    return e('€ ' . number_format($amount, 2, ',', '.'));
}

function programPrice(array $config, string $programKey, string $priceType): float
{
    return (float) $config['prices']['bezoek'][$programKey][$priceType];
}

function moduleLabels(array $config, array $moduleKeys): array
{
    return array_map(
        static fn (string $moduleKey): string => (string) ($config['moduleLabels'][$moduleKey] ?? $moduleKey),
        $moduleKeys,
    );
}

function renderList(array $items): void
{
    echo '<ul>';

    foreach ($items as $item) {
        echo '<li>' . e($item) . '</li>';
    }

    echo '</ul>';
}

function renderProgramCard(
    array $config,
    string $title,
    string $sectorKey,
    string $programKey,
    string $priceType,
    ?string $choiceModuleNote = null,
): void {
    $program = $config['programs'][$programKey];
    $modules = $config['modules'][$sectorKey][$programKey];
    $standardModules = moduleLabels($config, $modules['standaard'] ?? []);
    $choiceModules = moduleLabels($config, $modules['keuze'] ?? []);
    ?>
    <article class="conditions-card">
        <div class="conditions-card__header">
            <h3><?= e($title); ?></h3>
            <p><?= e($program['beginTijd']); ?> - <?= e($program['eindTijd']); ?></p>
        </div>

        <dl class="conditions-meta">
            <div>
                <dt>Duur lesmodule</dt>
                <dd><?= e($program['duurLesmodule']); ?></dd>
            </div>
            <div>
                <dt>Prijs per leerling</dt>
                <dd><?= formatEuro(programPrice($config, $programKey, $priceType)); ?></dd>
            </div>
        </dl>

        <div class="conditions-card__body">
            <div>
                <h4>Standaardmodules</h4>
                <?php renderList($standardModules); ?>
            </div>

            <div>
                <h4>Keuzemodules</h4>
                <?php if ($choiceModules !== []) { ?>
                    <?php renderList($choiceModules); ?>
                <?php } else { ?>
                    <p class="conditions-note"><?= e($choiceModuleNote ?? 'Voor dit programma zijn geen keuzemodules beschikbaar.'); ?></p>
                <?php } ?>
            </div>
        </div>
    </article>
    <?php
}

function renderFoodOptions(array $options): void
{
    foreach ($options as $option) {
        $min = (int) $option['min'];
        $max = (int) $option['max'];
        $showLimits = !($min === 0 && $max === 1);
        ?>
        <article class="food-option">
            <div>
                <h4><?= e($option['label']); ?></h4>
                <p><?= e($option['description']); ?></p>
            </div>

            <dl>
                <div>
                    <dt>Prijs</dt>
                    <dd><?= formatEuro((float) $option['price']); ?></dd>
                </div>
                <?php if ($showLimits) { ?>
                    <div>
                        <dt>Afname</dt>
                        <dd>min. <?= e($min); ?>, max. <?= e($max); ?></dd>
                    </div>
                <?php } ?>
            </dl>
        </article>
        <?php
    }
}

$freeSupervisorRatio = (int) $policy['limieten']['leerlingenPerGratisBegeleider'];
$remiseLunch = $config['foodAndDrinkOptions']['lunch']['remise_lunch'];
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GeoFort Onderwijs — Prijzen en voorwaarden</title>
    <?= $vite->renderTags('resources/js/booking/voorwaarden.ts'); ?>
</head>
<body>
    <div class="conditions-page">
        <header class="conditions-hero">
            <nav class="conditions-nav" aria-label="Pagina navigatie">
                <a class="conditions-back-link" href="<?= e($returnUrl); ?>">Terug naar reserveringsformulier</a>
                <a class="conditions-document-link" href="<?= e($pdfUrl); ?>" target="_blank" rel="noopener">
                    Officiële PDF voorwaarden
                </a>
            </nav>

            <div class="conditions-hero__content">
                <p class="conditions-kicker">GeoFort Onderwijs</p>
                <h1>GeoFort Onderwijs — Prijzen en voorwaarden</h1>
                <p>
                    Op deze pagina vindt u een overzicht van de programma's, tarieven, inbegrepen onderdelen
                    en voorwaarden voor een onderwijsbezoek aan GeoFort.
                </p>
            </div>
        </header>

        <main class="conditions-main">
            <section class="conditions-section" aria-labelledby="po-title">
                <div class="conditions-section__intro">
                    <p class="conditions-kicker">Primair onderwijs</p>
                    <h2 id="po-title">Bezoek Primair Onderwijs</h2>
                </div>

                <div class="conditions-grid">
                    <?php renderProgramCard(
                        config: $config,
                        title: 'Ochtendprogramma PO',
                        sectorKey: 'primairOnderwijs',
                        programKey: 'ochtend',
                        priceType: 'basis',
                        choiceModuleNote: 'Het woensdagochtendprogramma heeft geen keuzemodules.',
                    ); ?>

                    <?php renderProgramCard(
                        config: $config,
                        title: 'Dagprogramma PO',
                        sectorKey: 'primairOnderwijs',
                        programKey: 'dag',
                        priceType: 'basis',
                    ); ?>
                </div>
            </section>

            <section class="conditions-section" aria-labelledby="vo-title">
                <div class="conditions-section__intro">
                    <p class="conditions-kicker">Voortgezet onderwijs</p>
                    <h2 id="vo-title">Bezoek Voortgezet Onderwijs</h2>
                </div>

                <div class="conditions-grid">
                    <?php renderProgramCard(
                        config: $config,
                        title: 'Dagprogramma VO - Onderbouw',
                        sectorKey: 'voortgezetOnderbouw',
                        programKey: 'dag',
                        priceType: 'voortgezet',
                    ); ?>

                    <?php renderProgramCard(
                        config: $config,
                        title: 'Dagprogramma VO - Bovenbouw',
                        sectorKey: 'voortgezetBovenbouw',
                        programKey: 'dag',
                        priceType: 'voortgezet',
                    ); ?>
                </div>
            </section>

            <section class="conditions-section" aria-labelledby="general-title">
                <div class="conditions-section__intro">
                    <p class="conditions-kicker">Voorwaarden</p>
                    <h2 id="general-title">Algemene voorwaarden schoolbezoek</h2>
                </div>

                <div class="conditions-panel">
                    <?php renderList([
                        'Per ' . $freeSupervisorRatio . ' leerlingen is 1 begeleider gratis.',
                        'Extra begeleiders worden berekend tegen leerlingprijs.',
                        'Onderwijs, begeleiding, conceptrooster en koffie/thee voor begeleiders zijn inbegrepen waar van toepassing.',
                        'De vegetarische snack en plantaardige chocolademelk bij Voedsel Innovatie zijn inbegrepen.',
                        'CJP/Cultuurkaart is geldig onder voorwaarden.',
                        'Museumkaart is niet geldig op onderwijsarrangementen.',
                        'Allergieën kunnen worden doorgegeven via het opmerkingenveld.',
                        'Het meegestuurde rooster is een conceptrooster en kan nog afwijken.',
                        'Voor de Remiselunch geldt een minimale afname van ' . (int) $remiseLunch['min'] . ' en maximale afname van ' . (int) $remiseLunch['max'] . '.',
                        (string) $config['practicalInfo']['vatText'],
                    ]); ?>
                </div>
            </section>

            <section class="conditions-section" aria-labelledby="food-title">
                <div class="conditions-section__intro">
                    <p class="conditions-kicker">Eten en drinken</p>
                    <h2 id="food-title">Eten en drinken</h2>
                </div>

                <div class="conditions-food-layout">
                    <article class="conditions-panel">
                        <h3>Inbegrepen</h3>
                        <ul>
                            <?php foreach ($config['foodAndDrinkInfo']['included'] as $included) { ?>
                                <li>
                                    <strong><?= e($included['label']); ?></strong>
                                    <span><?= e($included['description']); ?></span>
                                </li>
                            <?php } ?>
                        </ul>

                        <h3>Goed om te weten</h3>
                        <?php renderList($config['foodAndDrinkInfo']['notes']); ?>
                    </article>

                    <div class="food-options-group">
                        <h3>Bij te boeken snacks</h3>
                        <?php renderFoodOptions($config['foodAndDrinkOptions']['snacks']); ?>
                    </div>

                    <div class="food-options-group">
                        <h3>Bij te boeken lunch</h3>
                        <?php renderFoodOptions($config['foodAndDrinkOptions']['lunch']); ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
