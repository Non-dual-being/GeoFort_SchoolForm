<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\ProgramConfiguration\BookingProgramConfigurationSnapshot;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingProgramConfigurationSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, BookingProgramConfigurationSnapshot $expected, BookingProgramConfigurationSnapshot $proposed): bool
    {
        try {
            $parameters = [
                ':id'=>$id, ':status'=>$expected->status, ':visitDate'=>$expected->visitDate,
                ':expectedProgram'=>$expected->program, ':expectedStudents'=>$expected->studentCount,
                ':expectedModule'=>$expected->choiceModule,
            ];
            $mainChanged = $expected->program !== $proposed->program
                || $expected->studentCount !== $proposed->studentCount
                || $expected->choiceModule !== $proposed->choiceModule;
            $statement = $this->pdo->prepare($mainChanged ? <<<'SQL'
                UPDATE aanvragen SET programma=:program, aantal_leerlingen=:students, keuzemodule_key=:module
                WHERE id=:id AND status=:status AND bezoekdatum=:visitDate AND programma=:expectedProgram
                  AND aantal_leerlingen=:expectedStudents AND keuzemodule_key <=> :expectedModule
                SQL
                : <<<'SQL'
                SELECT id FROM aanvragen
                WHERE id=:id AND status=:status AND bezoekdatum=:visitDate AND programma=:expectedProgram
                  AND aantal_leerlingen=:expectedStudents AND keuzemodule_key <=> :expectedModule
                FOR UPDATE
                SQL);
            if ($mainChanged) $parameters += [':program'=>$proposed->program, ':students'=>$proposed->studentCount, ':module'=>$proposed->choiceModule];
            $statement->execute($parameters);
            if ($mainChanged ? $statement->rowCount() !== 1 : $statement->fetchColumn() === false) return false;

            $delete = $this->pdo->prepare('DELETE FROM aanvraag_onderwijs_selecties WHERE aanvraag_id=:id');
            $delete->execute([':id'=>$id]);
            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO aanvraag_onderwijs_selecties
                (aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position)
                VALUES (:id,:sector,:sectorLabel,:level,:levelLabel,:levelPosition,:groupKey,:groupLabel,:groupPosition)
                SQL);
            foreach ($proposed->educationSelection->selectedLevels as $levelPosition => $level) {
                foreach ($proposed->educationSelection->selectedGroupsByLevel[$level] ?? [] as $groupPosition => $group) {
                    $insert->execute([
                        ':id'=>$id, ':sector'=>$proposed->educationSelection->sector,
                        ':sectorLabel'=>BookingProgramConfig::getSchoolSectorLabel($proposed->educationSelection->sector),
                        ':level'=>$level, ':levelLabel'=>BookingProgramConfig::SCHOOL_LEVELS[$proposed->educationSelection->sector][$level]['label'],
                        ':levelPosition'=>$levelPosition+1, ':groupKey'=>$group,
                        ':groupLabel'=>BookingProgramConfig::SCHOOL_LEVELS[$proposed->educationSelection->sector][$level]['groups'][$group],
                        ':groupPosition'=>$groupPosition+1,
                    ]);
                }
            }
            return true;
        } catch (PDOException $exception) {
            throw new RuntimeException('Programmaconfiguratie kon niet worden gewijzigd.', 0, $exception);
        }
    }
}
