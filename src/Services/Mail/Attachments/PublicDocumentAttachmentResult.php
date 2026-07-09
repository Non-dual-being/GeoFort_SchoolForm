<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Attachments;

final readonly class PublicDocumentAttachmentResult
{
    public function __construct(
        public bool $found,
        public ?string $path,
        public string $filename,
        public ?string $mimeType,
        public ?string $reason,
    ) {}
}
