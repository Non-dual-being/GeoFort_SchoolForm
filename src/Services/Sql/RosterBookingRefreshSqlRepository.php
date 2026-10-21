<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use LogicException;

final readonly class RosterBookingRefreshSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function bookingHistory(int $bookingId): array
    {
        $query = $this->pdo->prepare('SELECT changed_fields_json FROM booking_change_history WHERE booking_id=? ORDER BY id DESC');
        $query->execute([$bookingId]);
        return array_map(static fn(string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function confirmedContext(int $planId, string $fingerprint): ?array
    {
        $query = $this->pdo->prepare('SELECT changes_json FROM roster_booking_refresh_history WHERE roster_plan_id=? ORDER BY id DESC');
        $query->execute([$planId]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $changes = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            $context = $changes['sourceContext'] ?? null;
            if (is_array($context) && hash_equals($fingerprint, \GeoFort\Services\Dashboard\Roster\RosterSourceFingerprint::fromContext($context))) return $context;
        }
        return null;
    }

    public function apply(array $before, array $preview, int $actor): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Aanvraagwijzigingen vereisen een transactie.');
        $id = $before['id'];
        $this->pdo->prepare('INSERT INTO roster_booking_refresh_history(roster_plan_id,source_revision,before_json,changes_json,created_by_admin_id) VALUES(?,?,?,?,?)')
            ->execute([$id, $before['revision'], json_encode($before, JSON_THROW_ON_ERROR), json_encode($preview, JSON_THROW_ON_ERROR), $actor]);
        $sessions = new RosterSessionSqlRepository($this->pdo);
        foreach ($preview['affectedLessons'] as $lesson) {
            if ($lesson['remainingGroupIds'] === []) $sessions->delete($lesson['id'], $id);
            else {
                $sessions->replaceGroups($lesson['id'], $lesson['remainingGroupIds']);

            }
        }
        if ($preview['clearStaffing']) {
            foreach (['roster_session_staff', 'roster_session_group_staff'] as $table) {
                $this->pdo->prepare('DELETE a FROM '.$table.' a JOIN roster_sessions s ON s.id=a.roster_session_id WHERE s.roster_plan_id=?')->execute([$id]);
            }
            (new RosterSessionCookSqlRepository($this->pdo))->clearForPlan($id);
            $this->pdo->prepare("UPDATE roster_session_groups g JOIN roster_sessions s ON s.id=g.roster_session_id SET g.supervision_kind='open' WHERE s.roster_plan_id=? AND (g.supervision_kind IS NULL OR g.supervision_kind<>'school')")->execute([$id]);
            $this->pdo->prepare("UPDATE roster_plan_staffing_settings SET cook_staff_id=NULL,staffing_mode='lesson_only' WHERE roster_plan_id=?")->execute([$id]);
        }
        $remove = $this->pdo->prepare('DELETE FROM roster_groups WHERE roster_plan_id=? AND id=?');
        foreach ($preview['removedGroups'] as $group) $remove->execute([$id, $group['id']]);
        $add = $this->pdo->prepare('INSERT INTO roster_groups(roster_plan_id,label,position,student_count) VALUES(?,?,?,NULL)');
        for ($i = $preview['beforeGroupCount'] + 1; $i <= $preview['afterGroupCount']; $i++) $add->execute([$id, 'Groep '.$i, $i]);
        $this->pdo->prepare('UPDATE roster_plans SET visit_date=?,source_booking_fingerprint=? WHERE id=?')
            ->execute([$preview['afterDate'], $preview['sourceFingerprint'], $id]);
    }
}
