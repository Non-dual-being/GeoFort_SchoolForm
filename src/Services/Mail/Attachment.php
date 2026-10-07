<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

final readonly class Attachment
{
    public function __construct(
        public string $path,
        public string $filename,
        public ?string $mimeType = null,
        public ?string $bytes = null,
    ) {}

    public static function fromBytes(string $bytes, string $filename, string $mimeType): self
    {
        return new self('', $filename, $mimeType, $bytes);
    }
}
