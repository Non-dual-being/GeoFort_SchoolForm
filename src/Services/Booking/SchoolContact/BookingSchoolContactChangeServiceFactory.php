<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\SchoolContact;

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\BookingSchoolContactSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;

final readonly class BookingSchoolContactChangeServiceFactory
{
    public function __construct(private PDO $pdo) {}
    public function create():BookingSchoolContactChangeService{return new BookingSchoolContactChangeService($this->pdo,new StoredBookingSqlRepository($this->pdo,new StoredBookingAssembler()),new BookingSchoolContactSqlRepository($this->pdo),new BookingChangeHistorySqlRepository($this->pdo),new BookingSchoolContactValidator());}
}
