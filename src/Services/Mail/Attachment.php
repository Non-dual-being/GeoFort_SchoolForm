<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail;

final readonly class Attachment
{
    public function __construct(
        public string $path,
        public string $filename,
    ) {}
}