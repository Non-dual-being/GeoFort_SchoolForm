<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;
use PDO;
final readonly class RosterJointWorkspaceSqlRepository
{
    public function __construct(private PDO $pdo) {}
    public function options(int $pairId): ?array
    {
        try {
            $q=$this->pdo->prepare('SELECT options_json FROM roster_joint_workspace_events WHERE pair_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([$pairId]);$value=$q->fetchColumn();
            return $value===false?null:json_decode($value,true,32,JSON_THROW_ON_ERROR);
        } catch (\PDOException $e) { if ($e->getCode()==='42S02') return null; throw $e; }
    }
    public function availability(int $planId, array $rows): void
    {
        $q=$this->pdo->prepare('UPDATE roster_plan_staff SET availability_start=?,availability_end=? WHERE roster_plan_id=? AND staff_id=?');
        foreach ($rows as $row) $q->execute([$row['availability_start'],$row['availability_end'],$planId,$row['staff_id']]);
    }
    public function clearCookFallback(int $planId): void
    {
        $this->pdo->prepare('UPDATE roster_plan_staffing_settings SET cook_staff_id=NULL WHERE roster_plan_id=?')->execute([$planId]);
    }
    public function record(int $pairId, int $actor, array $before, array $after, array $options): void
    {
        $q=$this->pdo->prepare('INSERT INTO roster_joint_workspace_events(pair_id,actor_id,before_json,after_json,options_json) VALUES (?,?,?,?,?)');
        $q->execute([$pairId,$actor,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),json_encode($options,JSON_THROW_ON_ERROR)]);
    }
}
