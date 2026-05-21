<?php
declare(strict_types=1);
namespace GeoFort\Utils;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;


final class Dateparser {
    private DateTimeZone $timezone;

    public function __construct() {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    public static function getDateTime(string $date): DateTimeImmutable {
        $datetime = createFromFormat(
            '!Y-m-d',
            $date,
            $this->timezone
        );

        if (!$datetime || $datetime->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(
                __CLASS__ . '[' . __METHOD__ . '] : Ongeldige datum meegeven'
            );
        }

        return $datetime;
    }

    public static function getDateString(DateTimeImmutable $datetime): string {
        return $datetime->format('Y-m-d');
    }
}