<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Services\Booking\Data\{BookingRequestData, EducationSelectionData, FoodAndDrinkSelectionData};
use GeoFort\Services\Dashboard\Booking\{DashboardBookingFilterParser, DashboardBookingListService};
use GeoFort\Services\Sql\{DashboardBookingSqlService, RequestService};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$parser = new DashboardBookingFilterParser();
foreach ([null, '', 'invalid', 'ASC', 'asc; DROP TABLE aanvragen', ['asc']] as $sort) {
    $assert($parser->parse(['sort' => $sort])->sort === 'desc', 'Ongeldige sortering valt niet terug op desc.');
}
$assert($parser->parse(['sort' => 'asc'])->sort === 'asc', 'asc wordt niet geaccepteerd.');

$database = DisposableBookingMariaDb::create('booking_sort');
try {
    $pdo = $database->pdo;
    $requests = new RequestService($pdo);
    $service = new DashboardBookingListService(new DashboardBookingSqlService($pdo));
    $fixtures = [];
    for ($index = 0; $index < 65; $index++) {
        // Shuffled insertion order and repeated dates catch sorting by ID and unstable ties.
        $day = intdiv(($index * 17) % 65, 2);
        $date = (new DateTimeImmutable('2027-03-01'))->modify("+{$day} days")->format('Y-m-d');
        $request = new BookingRequestData(
            'Sort school ' . $index, 'Nederland', 'Dijk 1', '1234 AB', 'Sortstad',
            '0345123456', '0612345678', 'Sanne', 'Jansen', 'sanne@example.test',
            $date, $date, 'Test', 'nee', null, null, 'primairOnderwijs', 'dag', 'Earth-Watch', 40, 4,
            new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier' => ['groep5']]),
            FoodAndDrinkSelectionData::fromStoredValues(0, 0, 0, 0, 0, 0, true), null, true,
        );
        $id = $requests->insert($request);
        $status = $index % 3 === 0 ? 'Definitief' : 'In optie';
        $sector = $index % 5 === 0 ? 'voortgezetOnderbouw' : 'primairOnderwijs';
        $program = $index % 7 === 0 ? 'ochtend' : 'dag';
        $module = $program === 'dag' ? 'Earth-Watch' : null;
        $pdo->prepare('UPDATE aanvragen SET status=?, onderwijs_sector=?, programma=?, keuzemodule_key=? WHERE id=?')
            ->execute([$status, $sector, $program, $module, $id]);
        $fixtures[] = ['id' => $id, 'date' => $date, 'status' => $status, 'sector' => $sector, 'program' => $program, 'module' => $module, 'search' => $request->schoolnaam];
    }

    $filterCases = [[], ['search' => 'Sort school 1'], ['status' => 'In optie'], ['sector' => 'primairOnderwijs'],
        ['program' => 'dag'], ['module' => 'Earth-Watch'], ['dateFrom' => '2027-03-10', 'dateTo' => '2027-03-25'],
        ['search' => 'Sort school', 'status' => 'In optie', 'sector' => 'primairOnderwijs', 'program' => 'dag',
            'module' => 'Earth-Watch', 'dateFrom' => '2027-03-10', 'dateTo' => '2027-03-25']];
    foreach ($filterCases as $query) {
        $matching = array_values(array_filter($fixtures, static function (array $row) use ($query): bool {
            foreach ($query as $key => $value) {
                if ($key === 'search' && !str_contains($row['search'], $value)) return false;
                if ($key === 'dateFrom' && $row['date'] < $value) return false;
                if ($key === 'dateTo' && $row['date'] > $value) return false;
                if (!in_array($key, ['search', 'dateFrom', 'dateTo'], true) && $row[$key] !== $value) return false;
            }
            return true;
        }));
        foreach ([null, 'desc', 'asc'] as $sort) {
            $expected = $matching;
            usort($expected, static fn (array $a, array $b): int =>
                ([$a['date'], $a['id']] <=> [$b['date'], $b['id']]) * ($sort === 'asc' ? 1 : -1));
            $actual = [];
            $pages = max(1, (int) ceil(count($expected) / 20));
            for ($pageNumber = 1; $pageNumber <= $pages; $pageNumber++) {
                $page = $service->getPage($parser->parse([...$query, 'sort' => $sort, 'page' => (string) $pageNumber]))->toArray();
                $ids = array_column($page['items'], 'id');
                $assert($page['pagination']['perPage'] === 20 && $page['pagination']['currentPage'] === $pageNumber, 'Paginacontract gewijzigd.');
                $assert($page['pagination']['totalItems'] === count($expected), 'Filters tellen onjuist.');
                $assert($ids === array_column(array_slice($expected, ($pageNumber - 1) * 20, 20), 'id'), 'Pagina bevat niet de juiste globaal gesorteerde resultaten.');
                array_push($actual, ...$ids);
            }
            $assert($actual === array_column($expected, 'id'), 'Paginering verliest of dupliceert aanvragen.');
        }
    }
    $last = $service->getPage($parser->parse(['sort' => 'asc', 'page' => '999']))->toArray();
    $assert($last['pagination']['currentPage'] === 4, 'Bestaande begrenzing tot laatste pagina werkt niet.');
    $empty = $service->getPage($parser->parse(['search' => 'Geen overeenkomst', 'sort' => 'asc']))->toArray();
    $assert($empty['items'] === [] && $empty['pagination']['currentPage'] === 1, 'Lege resultaten werken niet.');
    echo "OK: sortering op bezoekdatum/id, whitelist, 65 aanvragen, alle filters en paginering.\n";
} finally {
    $database->drop();
}
