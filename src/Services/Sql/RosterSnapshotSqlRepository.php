<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Roster\RosterFinalSnapshot;
use PDO;
use RuntimeException;

/** Append-only history; deliberately exposes no snapshot update/delete operation. */
final readonly class RosterSnapshotSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function append(int $planId, int $revision, int $actorId, string $time, array $content): int
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('INSERT INTO roster_plan_snapshots
            (roster_plan_id, source_revision, published_revision, format_version, content_json, content_hash, finalized_by_admin_id, finalized_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$planId, $revision, $revision + 1, RosterFinalSnapshot::FORMAT_VERSION,
            RosterFinalSnapshot::canonicalJson($content), RosterFinalSnapshot::hash($content), $actorId, $time]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $planId, int $snapshotId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM roster_plan_snapshots WHERE roster_plan_id = ? AND id = ?');
        $statement->execute([$planId, $snapshotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $content = json_decode($row['content_json'], true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($content) || !hash_equals($row['content_hash'], RosterFinalSnapshot::hash($content))) {
            throw new RuntimeException('De definitieve roosterinhoud voldoet niet aan de opgeslagen inhoudshash.');
        }
        $row['content'] = $content;
        return $row;
    }

    /** @return array{current:?array,historyCount:int} */
    public function state(int $planId): array
    {
        $statement = $this->pdo->prepare('SELECT status, revision, published_snapshot_id,
            (SELECT COUNT(*) FROM roster_plan_snapshots s WHERE s.roster_plan_id = p.id) AS history_count
            FROM roster_plans p WHERE id = ?');
        $statement->execute([$planId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return ['current' => null, 'historyCount' => 0];
        $current = $row['status'] === 'published' && $row['published_snapshot_id'] !== null
            ? $this->find($planId, (int) $row['published_snapshot_id']) : null;
        if ($current !== null && (int) $current['published_revision'] !== (int) $row['revision']) {
            throw new RuntimeException('De definitieve roosterrevisie komt niet overeen met de snapshot.');
        }
        return ['current' => $current, 'historyCount' => (int) $row['history_count']];
    }

    public function transition(array $plan, string $status, ?int $snapshotId, int $actorId, string $time): void
    {
        $this->requireTransaction();
        $update = $this->pdo->prepare('UPDATE roster_plans SET status = ?, published_snapshot_id = ?, revision = revision + 1,
            updated_by_admin_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND revision = ? AND status = ?');
        $update->execute([$status, $status === 'published' ? $snapshotId : null, $actorId, $plan['id'], $plan['revision'], $plan['status']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('De roosterstatus kon niet worden bijgewerkt.');
        $audit = $this->pdo->prepare('INSERT INTO roster_plan_lifecycle_events
            (roster_plan_id, snapshot_id, event_type, previous_status, new_status, previous_revision, new_revision, actor_admin_id, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $audit->execute([$plan['id'], $snapshotId, $status === 'published' ? 'finalized' : 'reopened',
            $plan['status'], $status, $plan['revision'], (int) $plan['revision'] + 1, $actorId, $time]);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Finale roosteropslag vereist een transactie.');
    }
}
