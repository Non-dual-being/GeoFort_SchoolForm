<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Mail\Attachment;
use RuntimeException;

final readonly class RosterSchoolDocument
{
    public const TYPE = 'school_pdf';
    public const MIME = 'application/pdf';

    public function __construct(
        public int $snapshotId,
        public string $snapshotHash,
        public string $templateVersion,
        public string $filename,
        public string $bytes,
        public string $hash,
    ) {
        if ($snapshotId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $snapshotHash)
            || !preg_match('/^GeoFort_Definitief_Lesrooster_[A-Za-z0-9_-]+\.pdf$/D', $filename)
            || strlen($filename) > 180 || !str_starts_with($bytes, '%PDF-') || strlen($bytes) > 5 * 1024 * 1024
            || !hash_equals(hash('sha256', $bytes), $hash)) throw new RuntimeException('Ongeldig definitief schooldocument.');
    }

    public function attachment(): Attachment
    {
        return Attachment::fromBytes($this->bytes, $this->filename, self::MIME);
    }
}
