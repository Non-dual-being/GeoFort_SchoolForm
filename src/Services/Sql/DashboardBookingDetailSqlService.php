<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class DashboardBookingDetailSqlService
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, mixed>|null */
    public function findBooking(int $id): ?array
    {
        $sql = <<<'SQL'
            SELECT id, status, schoolnaam, land, adres, postcode, plaats,
                   school_telefoonnummer, contactpersoon_telefoonnummer,
                   contactpersoon_voornaam, contactpersoon_achternaam, email,
                   bezoekdatum, hoe_kent_u_geofort, opmerkingen, cjpPasGebruik,
                   cjpContactpersoonNaam, cjpPasnummer, onderwijs_sector,
                   programma, keuzemodule_key, aantal_leerlingen,
                   aantal_begeleiders, remise_break, kazerne_break,
                   fortgracht_break, glas_limonade, waterijsje, remise_lunch,
                   eigen_picknick, source_system, source_record_id
            FROM aanvragen
            WHERE id = :id
            SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][DashboardBookingDetailSqlService::findBooking]: ' . $exception->getMessage());
            throw new RuntimeException('Aanvraag kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return list<array<string, mixed>> */
    public function findEducationSelections(int $id): array
    {
        $sql = <<<'SQL'
            SELECT sector_key, sector_label, level_key, level_label,
                   level_position, group_key, group_label, group_position
            FROM aanvraag_onderwijs_selecties
            WHERE aanvraag_id = :id
            ORDER BY level_position ASC, group_position ASC, id ASC
            SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][DashboardBookingDetailSqlService::findEducationSelections]: ' . $exception->getMessage());
            throw new RuntimeException('Onderwijsselecties konden niet worden opgehaald.', 0, $exception);
        }
    }
}
