<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\{RosterPairSqlRepository,RosterPlanSqlRepository,BookingDaySettingsSqlRepository};
use PDO;
use Throwable;

final readonly class RosterPairService
{
    public function __construct(private PDO $pdo, private RosterPlanService $plans) {}

    public function link(array $expectedRevisions, string $workflow, int $actor, bool $confirmRestart = false): array
    {
        if (!in_array($workflow,['lessons_first','school_first'],true) || count($expectedRevisions)!==2) {
            throw new RosterPlanException('INVALID_PAIR',422,'Kies twee schoolroosters en de gewenste volgorde.');
        }
        $this->pdo->beginTransaction();
        try {
            $presentations=$this->lock($expectedRevisions);
            $repo=new RosterPairSqlRepository($this->pdo);
            $ids=array_keys($expectedRevisions);
            $first=$repo->forPlan($ids[0]); $second=$repo->forPlan($ids[1]);
            if ($first!==null || $second!==null) {
                if ($first===null || $second===null || $first['id']!==$second['id'] || $first['planIds']!==$ids) {
                    throw new RosterPlanException('ALREADY_LINKED',409,'Een school hoort al bij een gezamenlijke planning. Open die planning.');
                }
                $this->pdo->commit();
                return $first;
            }
            $date=$presentations[0]['visitDate'];
            foreach ($presentations as $plan) {
                if ($plan['visitDate']!==$date || !in_array($plan['status'],['concept','published'],true)
                    || ($plan['status']==='published' && !($plan['finalization']['trusted']??false))) {
                    throw new RosterPlanException('INVALID_PAIR',422,'Gebruik actieve schoolroosters op dezelfde bezoekdatum.');
                }
            }
            if (!$repo->eligibleBookings(array_column($presentations,'bookingId'),$date)) {
                throw new RosterPlanException('PAIR_BOOKINGS_REQUIRED',422,'Kies twee actieve aanvragen op een dag met twee tot vier scholen.');
            }
            RosterPairGuard::groupCount(array_sum(array_map(static fn(array $p): int => count($p['groups']), $presentations)));
            if (!$confirmRestart) throw new RosterPlanException('PAIR_RESTART_CONFIRMATION_REQUIRED',422,'Bevestig dat beide schoolroosters opnieuw beginnen vanuit de aanvragen.');
            foreach ($ids as $id) $this->plans->restartForPair($id,$actor);
            RosterPairGuard::groupCount(array_sum(array_map(fn(int $id): int => count($this->plans->getPlan($id)['groups']), $ids)));
            $pair=$repo->create($ids,$date,$workflow,$actor);
            RosterPairGuard::staff($this->pdo, $ids[0]);
            $this->pdo->commit();
            return $pair;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function archive(array $expectedRevisions, int $pairId, int $pairRevision, int $actor): array
    {
        if (count($expectedRevisions)!==2) throw new RosterPlanException('INVALID_PAIR',422,'Bevestig beide schoolroosters.');
        $this->pdo->beginTransaction();
        try {
            $presentations=$this->lock($expectedRevisions);
            $repo=new RosterPairSqlRepository($this->pdo);
            $pair=$repo->forPlan(array_key_first($expectedRevisions));
            if ($pair===null || $pair['id']!==$pairId || $pair['revision']!==$pairRevision || $pair['planIds']!==array_keys($expectedRevisions)) {
                throw new RosterPlanException('STALE_PAIR',409,'De gezamenlijke planning is gewijzigd. Laad beide scholen opnieuw.');
            }
            $repo->archive($pair,$presentations,$actor);
            $this->pdo->commit();
            return ['archivedPlanIds'=>$pair['planIds']];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function lock(array $revisions): array
    {
        RosterStaffCatalogGuard::lock($this->pdo);
        $repository=new RosterPlanSqlRepository($this->pdo);
        $initial=$repository->findById(array_key_first($revisions));
        if ($initial===null) throw new RosterPlanException('PLAN_NOT_FOUND',404,'Het schoolrooster bestaat niet.');
        (new BookingDaySettingsSqlRepository($this->pdo))->lockDate($initial['visit_date']);
        $ids=array_keys($revisions); sort($ids,SORT_NUMERIC);
        foreach ($ids as $id) {
            $row=$repository->lockPlan($id);
            if ($row===null || $row['revision']!==$revisions[$id]) throw new RosterPlanException('STALE_REVISION',409,'Een schoolrooster is gewijzigd. Laad beide scholen opnieuw.');
        }
        return array_map(fn(int $id):array=>$this->plans->getPlan($id),array_keys($revisions));
    }
}
