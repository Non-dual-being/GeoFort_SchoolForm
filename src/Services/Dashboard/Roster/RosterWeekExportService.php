<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class RosterWeekExportService
{
    public function __construct(private PDO $pdo, private RosterPlanService $plans) {}

    public static function monday(string $week): DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/D', $week, $match)) {
            throw new RosterPlanException('INVALID_WEEK', 422, 'Kies een geldige roosterweek.');
        }
        $date = (new DateTimeImmutable('2000-01-03'))->setISODate((int) $match[1], (int) $match[2], 1);
        if ($date->format('o-\WW') !== $week) {
            throw new RosterPlanException('INVALID_WEEK', 422, 'Kies een geldige roosterweek.');
        }
        return $date;
    }

    public static function eligible(array $plan): bool
    {
        return $plan['bookingId'] !== null
            && in_array($plan['status'], ['concept', 'published'], true)
            && in_array($plan['bookingStatus'] ?? null, ['In optie', 'Definitief'], true);
    }

    /** @return list<array{key:string,label:string,rosterCount:int}> */
    public function weeks(): array
    {
        $weeks = [];
        foreach ($this->plans->listPlans() as $plan) {
            if (!self::eligible($plan)) continue;
            $key = (new DateTimeImmutable($plan['visitDate']))->format('o-\WW');
            $monday = self::monday($key);
            $weeks[$key] ??= ['key' => $key, 'label' => 'Week ' . $monday->format('W · o') . ' | '
                . $monday->format('d-m') . ' t/m ' . $monday->modify('+6 days')->format('d-m'), 'rosterCount' => 0];
            $weeks[$key]['rosterCount']++;
        }
        krsort($weeks);
        return array_values($weeks);
    }

    /** Read all schools and their frozen final presentations in one consistent transaction. */
    public function export(string $week): array
    {
        $monday = self::monday($week);
        $start = $monday->format('Y-m-d');
        $end = $monday->modify('+6 days')->format('Y-m-d');
        $this->pdo->beginTransaction();
        try {
            $plans = [];
            foreach ($this->plans->listPlans() as $row) {
                if (!self::eligible($row) || $row['visitDate'] < $start || $row['visitDate'] > $end) continue;
                $plan = $this->plans->getPlan($row['id']);
                if ($plan !== null) $plans[] = $plan + ['bookingStatus' => $row['bookingStatus']];
            }
            if ($plans === []) throw new RosterPlanException('EMPTY_WEEK', 404, 'Deze week heeft geen roosters voor aanvragen in optie of definitief.');
            usort($plans, static fn(array $a, array $b): int => [$a['visitDate'], $a['school']['name'], $a['id']] <=> [$b['visitDate'], $b['school']['name'], $b['id']]);
            $this->pdo->commit();
            return ['week' => $week, 'startDate' => $start, 'plans' => $plans];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
