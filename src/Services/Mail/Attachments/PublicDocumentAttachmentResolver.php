<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Attachments;

final readonly class PublicDocumentAttachmentResolver
{
    private const PUBLIC_DOCUMENT_DIR = 'assets/booking/documents';

    public function __construct(private string $publicPath)
    {}

    public function resolve(
        string $publicUrlPath,
        string $attachmentFilename,
    ): PublicDocumentAttachmentResult {
        $normalizedPath = str_replace('\\', '/', trim($publicUrlPath));

        if (!str_starts_with($normalizedPath, '/assets/booking/documents/')) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentpad valt buiten de toegestane documentenmap.',
            );
        }

        if (strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION)) !== 'pdf') {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentbijlage is geen PDF-bestand.',
            );
        }

        $baseDir = realpath(
            rtrim($this->publicPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLIC_DOCUMENT_DIR),
        );

        if ($baseDir === false) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentenmap bestaat niet.',
            );
        }

        $candidatePath = rtrim($this->publicPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalizedPath, '/'));

        $realPath = realpath($candidatePath);

        if ($realPath === false) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentbestand bestaat niet.',
            );
        }

        if (!$this->isWithinBaseDir($realPath, $baseDir)) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentbestand valt buiten de toegestane map.',
            );
        }

        if (!is_file($realPath)) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentpad is geen bestand.',
            );
        }

        if (!is_readable($realPath)) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentbestand is niet leesbaar.',
            );
        }

        if (!$this->hasPdfMimeType($realPath)) {
            return $this->notFound(
                filename: $attachmentFilename,
                reason: 'Documentbestand heeft geen PDF MIME-type.',
            );
        }

        return new PublicDocumentAttachmentResult(
            found: true,
            path: $realPath,
            filename: $attachmentFilename,
            mimeType: 'application/pdf',
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

    private function notFound(
        string $filename,
        string $reason,
    ): PublicDocumentAttachmentResult {
        return new PublicDocumentAttachmentResult(
            found: false,
            path: null,
            filename: $filename,
            mimeType: null,
            reason: $reason,
        );
    }
}
