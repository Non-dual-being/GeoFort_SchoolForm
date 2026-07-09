<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

final readonly class RosterAttachmentResolver
{
    private const ATTACHMENT_FILENAME = 'GeoFort_Onderwijs_Conceptrooster.pdf';
    private const PUBLIC_PDF_DIR = 'assets/booking/roosters/pdf';

    public function __construct(private string $publicPath)
    {}

    public function resolve(?string $pdfUrl): RosterAttachmentResult
    {
        if ($pdfUrl === null || trim($pdfUrl) === '') {
            return $this->notFound('Geen PDF-pad beschikbaar.');
        }

        $normalizedUrl = str_replace('\\', '/', trim($pdfUrl));

        if (!str_starts_with($normalizedUrl, '/assets/booking/roosters/pdf/')) {
            return $this->notFound('PDF-pad valt buiten de rooster-PDF map.');
        }

        $relativePath = ltrim($normalizedUrl, '/');
        $candidatePath = rtrim($this->publicPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION)) !== 'pdf') {
            return $this->notFound('Roosterbijlage is geen PDF-bestand.');
        }

        $baseDir = realpath(
            rtrim($this->publicPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLIC_PDF_DIR),
        );

        if ($baseDir === false) {
            return $this->notFound('Rooster-PDF map bestaat niet.');
        }

        $realPath = realpath($candidatePath);

        if ($realPath === false) {
            return $this->notFound('Rooster-PDF bestaat niet.');
        }

        if (!$this->isWithinBaseDir($realPath, $baseDir)) {
            return $this->notFound('Rooster-PDF valt buiten de toegestane map.');
        }

        if (!is_file($realPath)) {
            return $this->notFound('Rooster-PDF is geen bestand.');
        }

        if (!is_readable($realPath)) {
            return $this->notFound('Rooster-PDF is niet leesbaar.');
        }

        if (!$this->hasPdfMimeType($realPath)) {
            return $this->notFound('Roosterbijlage heeft geen PDF MIME-type.');
        }

        return new RosterAttachmentResult(
            found: true,
            path: $realPath,
            filename: self::ATTACHMENT_FILENAME,
            reason: null,
        );
    }

    private function isWithinBaseDir(string $realPath, string $baseDir): bool
    {
        $normalizedBase = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $realPath);

        return str_starts_with($normalizedPath, $normalizedBase);
    }

    private function hasPdfMimeType(string $realPath): bool
    {
        if (!function_exists('finfo_open')) {
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return true;
        }

        $mimeType = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        if (!is_string($mimeType) || $mimeType === '') {
            return true;
        }

        return $mimeType === 'application/pdf';
    }

    private function notFound(string $reason): RosterAttachmentResult
    {
        return new RosterAttachmentResult(
            found: false,
            path: null,
            filename: self::ATTACHMENT_FILENAME,
            reason: $reason,
        );
    }
}
