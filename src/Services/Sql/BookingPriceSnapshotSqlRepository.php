<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Booking\Pricing\BookingPriceSnapshot;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingPriceSnapshotSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function latest(int $bookingId): ?BookingPriceSnapshot
    {
        $statement = $this->pdo->prepare('SELECT * FROM booking_price_snapshots WHERE booking_id = :bookingId ORDER BY sequence_number DESC LIMIT 1');
        $statement->execute([':bookingId' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<BookingPriceSnapshot> */
    public function history(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM booking_price_snapshots WHERE booking_id = :bookingId ORDER BY sequence_number ASC');
        $statement->execute([':bookingId' => $bookingId]);
        return array_map(fn (array $row): BookingPriceSnapshot => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function append(BookingPriceSnapshot $snapshot): BookingPriceSnapshot
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Prijssnapshot vereist een actieve transactie.');
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO booking_price_snapshots (
              booking_id, sequence_number, previous_snapshot_id, snapshot_reason, calculation_state,
              pricing_version, currency_code, vat_meaning, vat_basis_points,
              visit_amount_incl_vat_cents, catering_amount_incl_vat_cents, total_amount_incl_vat_cents,
              total_amount_excl_vat_cents, vat_amount_cents, canonical_input_json, calculation_details_json,
              input_checksum, checksum_format_version, created_by_admin_id
            ) VALUES (
              :bookingId, :sequenceNumber, :previousId, :reason, :state, :pricingVersion, :currencyCode,
              :vatMeaning, :vatBasisPoints, :visit, :catering, :total, :excl, :vat, :inputJson,
              :detailsJson, :checksum, :checksumVersion, :adminId
            )
            SQL);
        try{$statement->execute([
            ':bookingId'=>$snapshot->bookingId, ':sequenceNumber'=>$snapshot->sequenceNumber, ':previousId'=>$snapshot->previousSnapshotId,
            ':reason'=>$snapshot->reason, ':state'=>$snapshot->calculationState, ':pricingVersion'=>$snapshot->pricingVersion,
            ':currencyCode'=>$snapshot->currencyCode, ':vatMeaning'=>$snapshot->vatMeaning, ':vatBasisPoints'=>$snapshot->vatBasisPoints,
            ':visit'=>$snapshot->visitAmountInclVatCents, ':catering'=>$snapshot->cateringAmountInclVatCents, ':total'=>$snapshot->totalAmountInclVatCents,
            ':excl'=>$snapshot->totalAmountExclVatCents, ':vat'=>$snapshot->vatAmountCents, ':inputJson'=>$snapshot->canonicalInputJson,
            ':detailsJson'=>json_encode($snapshot->details, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':checksum'=>$snapshot->inputChecksum, ':checksumVersion'=>$snapshot->checksumFormatVersion, ':adminId'=>$snapshot->createdByAdminId,
        ]);}catch(PDOException $exception){if((int)($exception->errorInfo[1]??0)===1062)throw new RuntimeException('PRICE_SNAPSHOT_CONFLICT',0,$exception);throw new RuntimeException('Prijssnapshot kon niet worden opgeslagen.',0,$exception);}
        return $this->latest($snapshot->bookingId) ?? throw new RuntimeException('Prijssnapshot kon niet worden herlezen.');
    }

    /** @param array<string, mixed> $row @throws JsonException */
    private function hydrate(array $row): BookingPriceSnapshot
    {
        $details = json_decode((string)$row['calculation_details_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($details)) throw new RuntimeException('Snapshotdetails zijn ongeldig.');
        $nullableInt = static fn (mixed $value): ?int => $value === null ? null : (int)$value;
        return new BookingPriceSnapshot((int)$row['id'],(int)$row['booking_id'],(int)$row['sequence_number'],$nullableInt($row['previous_snapshot_id']),(string)$row['snapshot_reason'],(string)$row['calculation_state'],$row['pricing_version'] === null?null:(string)$row['pricing_version'],$row['currency_code']===null?null:(string)$row['currency_code'],$row['vat_meaning']===null?null:(string)$row['vat_meaning'],$nullableInt($row['vat_basis_points']),$nullableInt($row['visit_amount_incl_vat_cents']),$nullableInt($row['catering_amount_incl_vat_cents']),$nullableInt($row['total_amount_incl_vat_cents']),$nullableInt($row['total_amount_excl_vat_cents']),$nullableInt($row['vat_amount_cents']),(string)$row['canonical_input_json'],$details,(string)$row['input_checksum'],(int)$row['checksum_format_version'],$nullableInt($row['created_by_admin_id']),(string)$row['created_at']);
    }
}
