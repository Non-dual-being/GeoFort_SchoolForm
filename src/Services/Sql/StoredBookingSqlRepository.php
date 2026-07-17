<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use PDO;
use PDOException;
use RuntimeException;

final readonly class StoredBookingSqlRepository
{
    private const BOOKING_COLUMNS = <<<'SQL'
        id, status, schoolnaam, land, adres, postcode, plaats,
        school_telefoonnummer, contactpersoon_telefoonnummer,
        contactpersoon_voornaam, contactpersoon_achternaam, email,
        bezoekdatum, hoe_kent_u_geofort, opmerkingen, cjpPasGebruik,
        cjpContactpersoonNaam, cjpPasnummer, onderwijs_sector,
        programma, keuzemodule_key, aantal_leerlingen, aantal_begeleiders,
        remise_break, kazerne_break, fortgracht_break, glas_limonade,
        waterijsje, remise_lunch, eigen_picknick, voorwaarden_akkoord,
        voorwaarden_akkoord_op, source_system, source_record_id,
        source_record_checksum, source_import_run_id
        SQL;

    public function __construct(private PDO $pdo, private StoredBookingAssembler $assembler) {}

    public function findById(int $id): ?StoredBooking
    {
        return $this->find($id, false);
    }

    /**
     * Fase-2-lockvolgorde binnen één transactie:
     * 1. aanvraagrij locken en herlezen;
     * 2. datumrij locken;
     * 3. disabled-date en definitieve dagtotalen opnieuw lezen.
     */
    public function findByIdForUpdate(int $id): ?StoredBooking
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Aanvraaglock vereist een actieve database-transactie.');
        }

        return $this->find($id, true);
    }

    private function find(int $id, bool $forUpdate): ?StoredBooking
    {
        try {
            $lockClause = $forUpdate ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare(
                'SELECT ' . self::BOOKING_COLUMNS . ' FROM aanvragen WHERE id = :id' . $lockClause,
            );
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return null;

            $selections = $this->pdo->prepare(<<<'SQL'
                SELECT sector_key, level_key, group_key, level_position, group_position, id
                FROM aanvraag_onderwijs_selecties
                WHERE aanvraag_id = :id
                ORDER BY level_position ASC, group_position ASC, id ASC
                SQL);
            $selections->bindValue(':id', $id, PDO::PARAM_INT);
            $selections->execute();
            return $this->assembler->assemble($row, $selections->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $exception) {
            throw new RuntimeException('Opgeslagen aanvraag kon niet worden opgehaald.', 0, $exception);
        }
    }
}
