<?php

declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

use GeoFort\Booking\FourModuleProgram;

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

    public function termsUrlForProgram(?string $programVersion): string
    {
        if ($programVersion === FourModuleProgram::VERSION && $this->voorwaardenUrl !== '') {
            return $this->voorwaardenUrl . '?variant=four';
        }

        return $this->voorwaardenUrl;
    }

    public function onderwijsMailtoUrl(): string
    {
        return 'mailto:' . $this->onderwijsEmail;
    }
}
