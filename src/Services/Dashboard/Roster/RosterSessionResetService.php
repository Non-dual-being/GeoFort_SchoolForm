<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class RosterSessionResetService
{
    public function __construct(
        private PDO $pdo,
        private RosterPlanSqlRepository $plans,
        private RosterSessionSqlRepository $sessions,
    ) {}

    /** @return array{planId:int,revision:int,deletedSessionCount:int,alreadyEmpty:bool} */
    public function reset(int $planId, int $expectedRevision, int $actorId): array
    {
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            // Same order as session editing/generation; no day or booking mutation.
            RosterStaffCatalogGuard::lock($this->pdo);
            $plan = $this->plans->lockPlan($planId);
            if ($plan === null) {
                throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
            }
            if ($plan['status'] !== 'concept') {
                throw new RosterPlanException('PLAN_NOT_EDITABLE', 409, 'Alleen een conceptrooster kan worden leeggemaakt. Heropen een definitief rooster eerst.');
            }
            if ($plan['revision'] !== $expectedRevision) {
                throw new RosterPlanException('ROSTER_REVISION_CONFLICT', 409, 'Het rooster is ondertussen gewijzigd. Laad het opnieuw en controleer de lesindeling voordat je opnieuw bevestigt.');
            }

            $counts = $this->sessions->typeCountsForPlan($planId);
            $activityCount = $counts['activity'] ?? 0;
            if ($activityCount === 0) {
                // Release read locks without issuing DELETE/UPDATE or touching metadata.
                $this->pdo->rollBack();
                return ['planId' => $planId, 'revision' => $plan['revision'], 'deletedSessionCount' => 0, 'alreadyEmpty' => true];
            }
            if (array_diff(array_keys($counts), ['activity']) !== []) {
                throw new RosterPlanException('UNSUPPORTED_SESSION_TYPE', 409, 'Dit rooster bevat een onbekend sessietype. Er is niets verwijderd; laat het rooster eerst controleren.');
            }

            $deleted = $this->sessions->deleteActivitiesByPlanId($planId);
            if ($deleted !== $activityCount) {
                throw new RuntimeException('Het aantal verwijderde lessen komt niet overeen met de gecontroleerde lesindeling.');
            }
            $revision = $this->plans->advanceRevision($planId, $actorId);
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }

            return ['planId' => $planId, 'revision' => $revision, 'deletedSessionCount' => $deleted, 'alreadyEmpty' => false];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

