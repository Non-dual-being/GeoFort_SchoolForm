<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final class RosterSqlService
{
    public function __construct(private readonly PDO $pdo)
    {}

    /**
     * @return array{
     *   id: int,
     *   schooltype: string,
     *   keuzemodule: string,
     *   leerlingen_min: int,
     *   leerlingen_max: int,
     *   programmaduur: string,
     *   afbeelding: string,
     *   pdf: string|null
     * }|null
     */
    public function findRoster(
        string $schoolType,
        string $moduleKey,
        string $programDuration,
        int $studentCount,
    ): ?array {
        try {
            $sql = "
                SELECT
                    id,
                    schooltype,
                    keuzemodule,
                    leerlingen_min,
                    leerlingen_max,
                    programmaduur,
                    afbeelding,
                    pdf
                FROM roosters
                WHERE schooltype = :schooltype
                  AND keuzemodule = :keuzemodule
                  AND programmaduur = :programmaduur
                  AND leerlingen_min <= :studentCountForMinimum
                  AND leerlingen_max >= :studentCountForMaximum
                ORDER BY
                    leerlingen_min ASC,
                    leerlingen_max ASC,
                    id ASC
                LIMIT 2
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':schooltype' => $schoolType,
                ':keuzemodule' => $moduleKey,
                ':programmaduur' => $programDuration,
                ':studentCountForMinimum' => $studentCount,
                ':studentCountForMaximum' => $studentCount,
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return null;
            }

            if (count($rows) > 1) {
                error_log(sprintf(
                    '[CONFIG ERROR][RosterSqlService::findRoster] Overlappende roosterintervallen voor schooltype=%s, module=%s, programmaduur=%s, leerlingenaantal=%d.',
                    $schoolType,
                    $moduleKey,
                    $programDuration,
                    $studentCount,
                ));

                throw new RuntimeException(
                    'Meerdere passende roosters gevonden door overlappende intervallen.',
                );
            }

            return $this->normalizeRosterRow($rows[0]);
        } catch (PDOException $e) {
            error_log('[SQL ERROR][RosterSqlService::findRoster]: ' . $e->getMessage());

            throw new RuntimeException(
                'Rooster kon niet worden opgehaald',
                0,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: int,
     *   schooltype: string,
     *   keuzemodule: string,
     *   leerlingen_min: int,
     *   leerlingen_max: int,
     *   programmaduur: string,
     *   afbeelding: string,
     *   pdf: string|null
     * }
     */
    private function normalizeRosterRow(array $row): array
    {
        $stringColumns = [
            'schooltype',
            'keuzemodule',
            'programmaduur',
            'afbeelding',
        ];

        foreach ($stringColumns as $column) {
            if (!array_key_exists($column, $row) || !is_string($row[$column])) {
                throw new RuntimeException(
                    "Rooster bevat een ongeldige databasekolom: {$column}",
                );
            }
        }

        if (!array_key_exists('pdf', $row) || ($row['pdf'] !== null && !is_string($row['pdf']))) {
            throw new RuntimeException('Rooster bevat een ongeldige databasekolom: pdf');
        }

        return [
            'id' => $this->normalizeIntegerColumn($row, 'id'),
            'schooltype' => $row['schooltype'],
            'keuzemodule' => $row['keuzemodule'],
            'leerlingen_min' => $this->normalizeIntegerColumn($row, 'leerlingen_min'),
            'leerlingen_max' => $this->normalizeIntegerColumn($row, 'leerlingen_max'),
            'programmaduur' => $row['programmaduur'],
            'afbeelding' => $row['afbeelding'],
            'pdf' => $row['pdf'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function normalizeIntegerColumn(array $row, string $column): int
    {
        if (
            !array_key_exists($column, $row)
            || !(is_int($row[$column]) || (is_string($row[$column]) && preg_match('/^\d+$/', $row[$column]) === 1))
        ) {
            throw new RuntimeException(
                "Rooster bevat een ongeldige databasekolom: {$column}",
            );
        }

        return (int) $row[$column];
    }
}
