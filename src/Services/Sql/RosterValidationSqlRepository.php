<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;

/** Reads stored relations without presentation filtering or relationship repair. */
final readonly class RosterValidationSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function pairForPlan(int $planId): ?array
    {
        return (new RosterPairSqlRepository($this->pdo))->forPlan($planId);
    }

    public function lockBooking(int $bookingId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM aanvragen WHERE id = ? FOR UPDATE');
        $statement->execute([$bookingId]);
        $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function load(int $planId): ?array
    {
        $plan = $this->rows('SELECT * FROM roster_plans WHERE id = ?', [$planId])[0] ?? null;
        if ($plan === null) return null;
        $booking = $plan['booking_id'] === null ? null
            : ($this->rows('SELECT * FROM aanvragen WHERE id = ?', [$plan['booking_id']])[0] ?? null);
        $cooks=(new RosterSessionCookSqlRepository($this->pdo))->forPlan($planId);
        $sessions=$this->rows('SELECT * FROM roster_sessions WHERE roster_plan_id = ? ORDER BY start_time, end_time, id', [$planId]);
        foreach($sessions as &$session) if(isset($cooks[(int)$session['id']])) $session['cook_staff_id']=$cooks[(int)$session['id']];
        unset($session);
        return [
            'plan' => $plan,
            'booking' => $booking,
            'educationSelections' => $booking === null ? [] : $this->rows(
                'SELECT * FROM aanvraag_onderwijs_selecties WHERE aanvraag_id = ? ORDER BY level_position, group_position, id', [$booking['id']]),
            'context' => $this->rows('SELECT * FROM roster_manual_plan_context WHERE roster_plan_id = ?', [$planId])[0] ?? null,
            'groups' => $this->rows('SELECT * FROM roster_groups WHERE roster_plan_id = ? ORDER BY position, id', [$planId]),
            'sessions' => $sessions,
            // Include incoming links too: a group may wrongly point to another plan's session.
            'links' => $this->rows('SELECT sg.*, s.roster_plan_id AS session_plan_id, g.roster_plan_id AS group_plan_id
                FROM roster_session_groups sg LEFT JOIN roster_sessions s ON s.id = sg.roster_session_id
                LEFT JOIN roster_groups g ON g.id = sg.roster_group_id
                WHERE s.roster_plan_id = ? OR g.roster_plan_id = ? ORDER BY sg.roster_session_id, sg.roster_group_id', [$planId, $planId]),
            'sessionStaff' => $this->rows('SELECT ss.* FROM roster_session_staff ss
                JOIN roster_sessions s ON s.id = ss.roster_session_id WHERE s.roster_plan_id = ? ORDER BY ss.roster_session_id, ss.staff_id', [$planId]),
            'groupStaff' => $this->rows('SELECT gs.*, s.roster_plan_id AS session_plan_id, g.roster_plan_id AS group_plan_id
                FROM roster_session_group_staff gs LEFT JOIN roster_sessions s ON s.id = gs.roster_session_id
                LEFT JOIN roster_groups g ON g.id = gs.roster_group_id
                WHERE s.roster_plan_id = ? OR g.roster_plan_id = ? ORDER BY gs.roster_session_id, gs.roster_group_id, gs.staff_id', [$planId, $planId]),
            'staff' => $this->rows('SELECT id, display_name, is_active, can_guide, can_cook FROM roster_staff_members ORDER BY id'),
            'skills' => $this->rows('SELECT staff_id, module_key, preference_rank FROM roster_staff_module_preferences ORDER BY staff_id, module_key'),
            'selectedStaff' => $this->rows('SELECT * FROM roster_plan_staff WHERE roster_plan_id = ? ORDER BY staff_id', [$planId]),
            'settings' => $this->rows('SELECT * FROM roster_plan_staffing_settings WHERE roster_plan_id = ?', [$planId])[0] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function otherPlans(string $date, int $planId): array
    {
        return $this->rows('SELECT id, status, revision, published_snapshot_id FROM roster_plans
            WHERE visit_date = ? AND id <> ? AND status IN (\'concept\', \'published\') ORDER BY id', [$date, $planId]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
