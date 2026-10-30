<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use GeoFort\Services\Survey\VisitSurveyPolicy;
use PDO;

final readonly class VisitSurveySqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function recordConsent(int $bookingId, string $email): void
    {
        $this->execute('INSERT INTO visit_survey_consents (booking_id,email_hash,wording_version,granted_at) VALUES (?,?,?,?)',
            [$bookingId, VisitSurveyPolicy::emailHash($email), VisitSurveyPolicy::CONSENT_VERSION, date('Y-m-d H:i:s')]);
    }

    public function settings(): array
    {
        $r = $this->pdo->query('SELECT * FROM visit_survey_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('Instellingen voor bezoekevaluaties ontbreken.');
        return ['enabled' => (bool) $r['enabled'], 'delayDays' => (int) $r['delay_days'], 'startDate' => $r['start_date'], 'revision' => (int) $r['revision']];
    }

    public function saveSettings(bool $enabled, int $delay, ?string $start, int $revision, int $actor): bool
    {
        return $this->execute('UPDATE visit_survey_settings SET enabled=?,delay_days=?,start_date=?,revision=revision+1,updated_by=?,updated_at=NOW() WHERE id=1 AND revision=?',
            [(int) $enabled, $delay, $start, $actor, $revision])->rowCount() === 1;
    }

    /** Only explicitly consenting requests enter this workflow. Never read contact data into reports. */
    public function candidates(?int $bookingId = null): array
    {
        $where = $bookingId === null ? '' : ' WHERE a.id=?';
        return $this->execute("SELECT a.id, a.schoolnaam, a.bezoekdatum, a.status, a.email, a.contactpersoon_voornaam, a.contactpersoon_achternaam,
            a.onderwijs_sector, a.programma, a.program_version, c.email_hash, c.withdrawn_at, c.excluded,
            i.status AS invitation_status, i.snapshot_json, i.sent_at, i.expires_at,
            r.satisfaction, r.favorite_module, r.tip, r.answered_at
            FROM visit_survey_consents c JOIN aanvragen a ON a.id=c.booking_id
            LEFT JOIN visit_survey_invitations i ON i.booking_id=c.booking_id
            LEFT JOIN visit_survey_responses r ON r.booking_id=c.booking_id" . $where . " ORDER BY a.bezoekdatum DESC, a.id DESC", $bookingId === null ? [] : [$bookingId])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function modules(int $bookingId): array
    {
        return $this->execute("SELECT DISTINCT s.module_key FROM roster_plans p JOIN roster_sessions s ON s.roster_plan_id=p.id
            WHERE p.booking_id=? AND p.status IN ('concept','published') AND s.item_type='activity' AND s.module_key IS NOT NULL ORDER BY s.module_key", [$bookingId])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function educationLevel(int $bookingId): array
    {
        return $this->execute('SELECT DISTINCT level_key FROM aanvraag_onderwijs_selecties WHERE aanvraag_id=?', [$bookingId])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function withLock(int $id, callable $work): mixed
    {
        $name = 'visit-survey:' . $this->pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $id;
        // MariaDB named locks coordinate worker, response and planner exclusion, including multiple workers.
        if ((int) $this->execute('SELECT GET_LOCK(?,0)', [$name])->fetchColumn() !== 1) throw new \RuntimeException('Deze evaluatie wordt verwerkt. Probeer het straks opnieuw.');
        try { return $work(); } finally { $this->execute('SELECT RELEASE_LOCK(?)', [$name]); }
    }

    public function claim(int $id, string $hash, array $snapshot, string $now, string $expiry): bool
    {
        return $this->execute("INSERT IGNORE INTO visit_survey_invitations (booking_id,token_hash,status,snapshot_json,attempted_at,expires_at) VALUES (?,?,'sending',?,?,?)",
            [$id, $hash, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now, $expiry])->rowCount() === 1;
    }

    public function delivery(int $id, bool $success, string $now): void
    {
        $this->execute('UPDATE visit_survey_invitations SET status=?,sent_at=? WHERE booking_id=? AND status=\'sending\'', [$success ? 'sent' : 'failed', $success ? $now : null, $id]);
    }

    public function invitation(string $hash): ?array
    {
        $r = $this->execute('SELECT i.*,c.withdrawn_at,c.excluded,a.status AS booking_status,a.bezoekdatum,a.email,c.email_hash
            FROM visit_survey_invitations i JOIN visit_survey_consents c ON c.booking_id=i.booking_id JOIN aanvragen a ON a.id=i.booking_id WHERE i.token_hash=?', [$hash])->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function answer(int $id, array $answer, string $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->execute('INSERT INTO visit_survey_responses (booking_id,satisfaction,favorite_module,tip,answered_at) VALUES (?,?,?,?,?)', [$id, $answer['satisfaction'], $answer['favoriteModule'], $answer['tip'], $now]);
            $this->execute("UPDATE visit_survey_invitations SET status='answered' WHERE booking_id=?", [$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function withdraw(int $id, string $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->execute('UPDATE visit_survey_consents SET withdrawn_at=? WHERE booking_id=?', [$now, $id]);
            $this->execute("UPDATE visit_survey_invitations SET status='withdrawn' WHERE booking_id=?", [$id]);
            $this->execute('DELETE FROM visit_survey_responses WHERE booking_id=?', [$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function exclude(int $id, int $actor): void
    {
        $this->execute('UPDATE visit_survey_consents SET excluded=1,excluded_by=? WHERE booking_id=?', [$actor, $id]);
    }

    private function execute(string $sql, array $args): \PDOStatement
    {
        $s = $this->pdo->prepare($sql); $s->execute($args); return $s;
    }
}
