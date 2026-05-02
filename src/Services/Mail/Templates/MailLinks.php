<?php
declare(strict_types=1);
namespace GeoFort\Services\Mail\Templates;

final readonly class MailLinks
{
    public function __construct(
        public string $baseUrl,
        public string $voorwaardenUrl,
        public string $onderwijsEmail,
        public string $websiteUrl = 'https://www.geofort.nl',
    ){}
}