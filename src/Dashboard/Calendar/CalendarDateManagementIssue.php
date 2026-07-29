<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarDateManagementIssue
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $code,
        public string $field,
        public string $title,
        public string $description,
        public array $metadata = [],
    ) {}

    /** @return array{code:string,field:string,title:string,description:string,metadata:array<string,mixed>} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
