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
        public string $geoFortLessonModulesUrl = 'https://www.geofort.nl/onderwijs/lesmodules/',
        public string $goGeoLessonModulesUrl = 'https://www.gogeo.nl/lesmodules/',
        public string $minecraftWorkshopsUrl = 'https://workshops.geocraft.nl/',
    ) {}

    public function bookingFormUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/';
    }

    public function onderwijsMailtoUrl(): string
    {
        return 'mailto:' . $this->onderwijsEmail;
    }
}