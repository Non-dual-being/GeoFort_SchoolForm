<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;

final readonly class RosterLessonPreferencesSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function forPlan(int $planId): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT preferences_json FROM roster_plan_lesson_preferences WHERE roster_plan_id = :id');
            $statement->execute([':id' => $planId]);
            $value = $statement->fetchColumn();
            return $value === false ? null : json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (PDOException $exception) {
            // Older installations remain readable; saving new choices requires the migration.
            if ($exception->getCode() === '42S02') return null;
            throw $exception;
        }
    }

    public function save(int $planId, array $preferences): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO roster_plan_lesson_preferences (roster_plan_id, preferences_json)
            VALUES (:id, :preferences)
            ON DUPLICATE KEY UPDATE preferences_json = VALUES(preferences_json)
            SQL);
        $statement->execute([
            ':id' => $planId,
            ':preferences' => json_encode($preferences, JSON_THROW_ON_ERROR),
        ]);
    }
}
