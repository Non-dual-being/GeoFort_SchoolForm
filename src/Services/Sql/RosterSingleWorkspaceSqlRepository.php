<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;
use PDO;
final readonly class RosterSingleWorkspaceSqlRepository
{
    public function __construct(private PDO $pdo) {}
    public function options(int $planId): ?array
    {
        try {
            $q=$this->pdo->prepare('SELECT options_json FROM roster_single_workspace_events WHERE plan_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([$planId]);$value=$q->fetchColumn();
            return $value===false?null:json_decode($value,true,32,JSON_THROW_ON_ERROR);
        } catch (\PDOException $e) { if ($e->getCode()==='42S02') return null; throw $e; }
    }
    public function record(int $planId, int $actor, array $before, array $after, array $options): void
    {
        $q=$this->pdo->prepare('INSERT INTO roster_single_workspace_events(plan_id,actor_id,before_json,after_json,options_json) VALUES (?,?,?,?,?)');
        $q->execute([$planId,$actor,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),json_encode($options,JSON_THROW_ON_ERROR)]);
    }
}
