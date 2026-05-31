<?php
declare(strict_types=1);
namespace GeoFort\Utils;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;


final class Dateparser {

    private static function getTimeZone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Amsterdam');
    }

    public static function getDateTime(string $date): DateTimeImmutable {
      
        $datetime = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            self::getTimezone()
        );

        if (!$datetime || $datetime->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(
                __CLASS__ . '[' . __METHOD__ . '] : Ongeldige datum meegeven'
            );
        }

        return $datetime;
    }

    public static function getDateString(DateTimeImmutable $datetime): string {
        return $datetime
            ->setTimezone(self::getTimezone())
            ->format('Y-m-d');
    }

    public static function getLongDutchDate(DateTimeImmutable $datetime): string
    {
        $formatter = new IntlDateFormatter(
            'nl_NL',
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            self::getTimezone(),
            IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM y'
        );

        $formatted = $formatter->format($datetime);

        if ($formatted === false) {
            throw new InvalidArgumentException(
                __CLASS__ . '[' . __METHOD__ . '] : Datum kon niet geformatteerd worden'
            );
        }

        return $formatted;
    }

    public static function getLongDutchDateFromString(string $date): string
    {
        return self::getLongDutchDate(self::getDateTime($date));
    }


}