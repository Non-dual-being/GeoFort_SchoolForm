<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Sql\RosterSchoolDocumentSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use RuntimeException;
use Throwable;

final readonly class RosterSchoolDocumentService
{
    public function __construct(
        private RosterSchoolDocumentSqlRepository $documents,
        private RosterSnapshotSqlRepository $snapshots,
        private StoredBookingSqlRepository $bookings,
        private RosterSchoolProjection $projection,
        private RosterSchoolPdfRenderer $renderer,
    ) {}

    /** Read-only metadata; never returns paths or snapshot contents and never renders/writes. */
    public function available(int $bookingId): ?array
    {
        try {
            $snapshot = $this->eligible($bookingId);
            $this->projection->fromSnapshot($snapshot['content']);
            $document = $this->documents->find((int) $snapshot['id']);
            if ($document !== null) $this->verify($document, $snapshot);
            return ['snapshotId' => (int) $snapshot['id'], 'revision' => (int) $snapshot['published_revision'],
                'finalizedAt' => str_replace(' ', 'T', $snapshot['finalized_at']) . 'Z',
                'visitDate' => $snapshot['content']['presentation']['visitDate'],
                ...(isset($snapshot['content']['jointFinalization']) ? ['jointPlanning' => true] : [])];
        } catch (RosterPlanException) {
            return null;
        }
    }

    /** Read only: never render or insert, including when the document is missing. */
    public function readPrepared(int $bookingId, int $snapshotId): RosterSchoolDocument
    {
        $snapshot = $this->eligible($bookingId, $snapshotId);
        $document = $this->documents->find($snapshotId);
        if ($document === null) {
            throw new RosterPlanException('ROSTER_DOCUMENT_NOT_PREPARED', 409,
                'Bereid het definitieve schoolrooster eerst voor voordat u het downloadt.');
        }
        $this->verify($document, $snapshot);
        $this->verify($document, $this->eligible($bookingId, $snapshotId));
        return $document;
    }

    /** Only POST use-cases may call this; prepare BEFORE the booking status transaction. */
    public function ensurePrepared(int $bookingId, int $snapshotId): RosterSchoolDocument
    {
        $snapshot = $this->eligible($bookingId, $snapshotId);
        $document = $this->documents->find($snapshotId);
        if ($document === null) {
            $school = $this->projection->fromSnapshot($snapshot['content']);
            $bytes = $this->renderer->render($school);
            $name = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $school['school']) ?? '', '-_');
            $filename = 'GeoFort_Definitief_Lesrooster_' . substr($name === '' ? 'school' : $name, 0, 80) . '_' . $school['visitDate'] . '.pdf';
            $document = $this->documents->append(new RosterSchoolDocument($snapshotId, $snapshot['content_hash'],
                RosterSchoolPdfWriter::TEMPLATE_VERSION, $filename, $bytes, hash('sha256', $bytes)));
        }
        $this->verify($document, $snapshot);
        // A reopen during rendering must also invalidate preview/preparation.
        $this->verify($document, $this->eligible($bookingId, $snapshotId));
        return $document;
    }

    /** Called after the existing date + booking locks, before any status/pricing write. */
    public function recheck(StoredBooking $booking, RosterSchoolDocument $document): void
    {
        $snapshot = $this->eligible($booking->id, $document->snapshotId, $booking, true);
        $this->verify($document, $snapshot);
        $saved = $this->documents->find($document->snapshotId);
        if ($saved === null || !hash_equals($saved->hash, $document->hash)) throw new RuntimeException('Schooldocument is gewijzigd.');
        $this->verify($saved, $snapshot);
    }

    private function eligible(int $bookingId, ?int $snapshotId = null, ?StoredBooking $booking = null, bool $lock = false): array
    {
        $plan = $this->documents->currentPlan($bookingId, $snapshotId, $lock);
        if ($plan === null) $this->unavailable();
        $booking ??= $this->bookings->findById($bookingId);
        if ($booking === null || $booking->visitDate !== $plan['visit_date']) $this->unavailable();
        try {
            $snapshot = $this->snapshots->find((int) $plan['id'], (int) $plan['published_snapshot_id']);
        } catch (Throwable) {
            $this->unavailable();
        }
        if ($snapshot === null || (int) $snapshot['published_revision'] !== (int) $plan['revision']
            || (int) $snapshot['format_version'] !== RosterFinalSnapshot::FORMAT_VERSION) $this->unavailable();
        $content = $snapshot['content'];
        $frozen = $content['stored']['plan'] ?? [];
        if (($content['formatVersion'] ?? null) !== RosterFinalSnapshot::FORMAT_VERSION
            || ($content['planId'] ?? null) !== (int) $plan['id']
            || ($content['publishedRevision'] ?? null) !== (int) $plan['revision']
            || (int) ($frozen['booking_id'] ?? 0) !== $bookingId
            || ($frozen['visit_date'] ?? null) !== $booking->visitDate
            || ($content['presentation']['visitDate'] ?? null) !== $booking->visitDate
            || ($frozen['planning_mode'] ?? null) !== $plan['planning_mode']) $this->unavailable();
        if ($plan['planning_mode'] === 'booking_locked') {
            if (!hash_equals((string) ($frozen['source_booking_fingerprint'] ?? ''), RosterSourceFingerprint::of($booking))) $this->unavailable();
        } elseif ($plan['planning_mode'] !== 'custom') $this->unavailable();
        // custom uses its frozen manual context; a different booking program is intentionally allowed.
        return $snapshot;
    }

    private function verify(RosterSchoolDocument $document, array $snapshot): void
    {
        if ($document->snapshotId !== (int) $snapshot['id'] || !hash_equals($snapshot['content_hash'], $document->snapshotHash)
            || !hash_equals(hash('sha256', $document->bytes), $document->hash)) throw new RuntimeException('Schooldocument en snapshot komen niet overeen.');
    }

    private function unavailable(): never
    {
        throw new RosterPlanException('ROSTER_ATTACHMENT_UNAVAILABLE', 409, 'Het gekozen definitieve rooster is niet meer beschikbaar voor deze boeking. Open de roosterkeuze opnieuw.');
    }
}
