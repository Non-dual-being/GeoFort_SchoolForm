<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Roster\RosterSchoolDocument;
use PDO;
use PDOException;
use RuntimeException;

/** Prepared reads and append-only document storage; no update/delete API. */
final readonly class RosterSchoolDocumentSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function currentPlan(int $bookingId, ?int $snapshotId = null, bool $lock = false): ?array
    {
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Snapshotcontrole vereist een transactie.');
        $sql = "SELECT * FROM roster_plans WHERE booking_id = ? AND status = 'published' AND published_snapshot_id IS NOT NULL";
        $params = [$bookingId];
        if ($snapshotId !== null) { $sql .= ' AND published_snapshot_id = ?'; $params[] = $snapshotId; }
        $statement = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find(int $snapshotId): ?RosterSchoolDocument
    {
        $statement = $this->pdo->prepare('SELECT * FROM roster_school_documents WHERE snapshot_id = ? AND document_type = ?');
        $statement->execute([$snapshotId, RosterSchoolDocument::TYPE]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ($row['mime_type'] !== RosterSchoolDocument::MIME) throw new RuntimeException('Ongeldig schooldocumenttype.');
        return new RosterSchoolDocument((int) $row['snapshot_id'], $row['snapshot_hash'], $row['template_version'], $row['filename'], $row['content_bytes'], $row['content_hash']);
    }

    public function append(RosterSchoolDocument $document): RosterSchoolDocument
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO roster_school_documents
                (snapshot_id, snapshot_hash, document_type, template_version, mime_type, filename, content_bytes, content_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$document->snapshotId, $document->snapshotHash, RosterSchoolDocument::TYPE,
                $document->templateVersion, RosterSchoolDocument::MIME, $document->filename, $document->bytes, $document->hash]);
        } catch (PDOException $exception) {
            // Concurrent first preview: return the winning immutable bytes, never overwrite them.
            if ($exception->getCode() !== '23000' || $this->find($document->snapshotId) === null) throw $exception;
        }
        return $this->find($document->snapshotId) ?? throw new RuntimeException('Schooldocument kon niet worden opgeslagen.');
    }
}
