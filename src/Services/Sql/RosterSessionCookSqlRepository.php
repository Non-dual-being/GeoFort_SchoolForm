<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;
use PDO;
use PDOException;

final readonly class RosterSessionCookSqlRepository
{
    public function __construct(private PDO $pdo) {}
    public function forPlan(int $id): array
    {
        try {
            $q=$this->pdo->prepare('SELECT c.roster_session_id,c.staff_id FROM roster_session_cooks c JOIN roster_sessions s ON s.id=c.roster_session_id WHERE s.roster_plan_id=?');
            $q->execute([$id]); $result=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['roster_session_id']]=(int)$row['staff_id'];
            return $result;
        } catch(PDOException $e) { if (($e->errorInfo[1]??null)===1146) return []; throw $e; }
    }
    public function clearForPlan(int $planId): void
    {
        $this->pdo->prepare('DELETE c FROM roster_session_cooks c JOIN roster_sessions s ON s.id=c.roster_session_id WHERE s.roster_plan_id=?')->execute([$planId]);
    }
    public function save(int $sessionId,int $staffId): void
    {
        $this->pdo->prepare('INSERT INTO roster_session_cooks(roster_session_id,staff_id) VALUES (?,?) ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id)')->execute([$sessionId,$staffId]);
    }
}
