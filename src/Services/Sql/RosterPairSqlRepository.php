<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use LogicException;

final readonly class RosterPairSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function forPlan(int $planId): ?array
    {
        try {
            $s = $this->pdo->prepare("SELECT p.* FROM roster_planning_pairs p JOIN roster_planning_pair_members m ON m.pair_id=p.id WHERE m.plan_id=? AND p.status='active'");
            $s->execute([$planId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Older databases remain usable before the additive migration is deployed.
            if (($e->errorInfo[1] ?? 0) === 1146) return null;
            throw $e;
        }
        if (!$row) return null;
        $s = $this->pdo->prepare('SELECT plan_id FROM roster_planning_pair_members WHERE pair_id=? ORDER BY position');
        $s->execute([$row['id']]);
        return ['id'=>(int)$row['id'], 'revision'=>(int)$row['revision'], 'visitDate'=>$row['visit_date'],
            'workflow'=>$row['workflow'], 'planIds'=>array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))];
    }

    public function archiveForPlan(int $planId): ?array
    {
        $s = $this->pdo->prepare('SELECT presentation_json, archived_at FROM roster_plan_archives WHERE plan_id=?');
        $s->execute([$planId]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [...json_decode($row['presentation_json'],true,512,JSON_THROW_ON_ERROR),
            'status'=>'archived', 'jointPlanning'=>null, 'archivedAt'=>$row['archived_at']];
    }

    public function eligibleBookings(array $ids, string $date): bool
    {
        $s=$this->pdo->prepare("SELECT id FROM aanvragen WHERE bezoekdatum=? AND status IN ('In optie','Definitief') ORDER BY id FOR UPDATE");
        $s->execute([$date]);
        $active=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        return count($active)>=2 && count($active)<=4 && count(array_unique($ids))===2 && array_diff($ids,$active)===[];
    }

    public function create(array $ids, string $date, string $workflow, int $actor): array
    {
        $this->requireTransaction();
        $s=$this->pdo->prepare('INSERT INTO roster_planning_pairs(visit_date,workflow,created_by_admin_id) VALUES(?,?,?)');
        $s->execute([$date,$workflow,$actor]);
        $pairId=(int)$this->pdo->lastInsertId();
        $s=$this->pdo->prepare('INSERT INTO roster_planning_pair_members(pair_id,plan_id,position) VALUES(?,?,?)');
        foreach ($ids as $i=>$id) $s->execute([$pairId,$id,$i+1]);
        return $this->forPlan($ids[0]);
    }

    public function archive(array $pair, array $presentations, int $actor): void
    {
        $this->requireTransaction();
        $insert=$this->pdo->prepare('INSERT INTO roster_plan_archives(plan_id,pair_id,original_booking_id,presentation_json,archived_by_admin_id) VALUES(?,?,?,?,?)');
        $update=$this->pdo->prepare("UPDATE roster_plans SET status='archived',booking_id=NULL,revision=revision+1,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        foreach ($presentations as $plan) {
            $insert->execute([$plan['id'],$pair['id'],$plan['bookingId'],json_encode($plan,JSON_THROW_ON_ERROR),$actor]);
            $update->execute([$actor,$plan['id']]);
        }
        $s=$this->pdo->prepare("UPDATE roster_planning_pairs SET status='archived',revision=revision+1,archived_by_admin_id=?,archived_at=CURRENT_TIMESTAMP WHERE id=? AND status='active' AND revision=?");
        $s->execute([$actor,$pair['id'],$pair['revision']]);
        if ($s->rowCount()!==1) throw new LogicException('Pair archive must be atomic.');
    }

    public function restartFromBooking(int $planId, string $fingerprint, int $actor): void
    {
        $this->requireTransaction();
        // Session children cascade; immutable snapshots and document history are untouched.
        foreach (['roster_sessions', 'roster_plan_staff_module_preferences', 'roster_plan_staff',
            'roster_plan_staffing_settings', 'roster_manual_plan_context', 'roster_groups'] as $table) {
            $s=$this->pdo->prepare('DELETE FROM '.$table.' WHERE roster_plan_id=?');
            $s->execute([$planId]);
        }
        $s=$this->pdo->prepare("UPDATE roster_plans SET planning_mode='booking_locked', status='concept', published_snapshot_id=NULL,
            source_booking_fingerprint=?,revision=revision+1,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $s->execute([$fingerprint,$actor,$planId]);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Pair mutations require a transaction.');
    }
}
