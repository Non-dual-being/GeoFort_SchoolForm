<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Attendance;

use GeoFort\Booking\Capacity\{BookingCapacityValidator,PolicyCapacityLimitProvider};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Sql\{BookingAttendanceSqlRepository,BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingRuleOverrideSqlRepository,DisabledDatesSqlService,StoredBookingSqlRepository};
use PDO;

final readonly class BookingAttendanceChangeServiceFactory
{
    public function __construct(private PDO $pdo){}
    public function create():BookingAttendanceChangeService{$disabled=new DisabledDatesSqlService($this->pdo);return new BookingAttendanceChangeService($this->pdo,new StoredBookingSqlRepository($this->pdo,new StoredBookingAssembler()),new BookingAttendanceSqlRepository($this->pdo),new BookingChangeHistorySqlRepository($this->pdo),new BookingDaySettingsSqlRepository($this->pdo),new BookingCalendarSqlService($this->pdo),new StoredBookingValidator($disabled),new PolicyCapacityLimitProvider(),new BookingCapacityValidator(),new BookingRuleOverrideSqlRepository($this->pdo),new BookingRuleOverridePolicy(),new AuthenticatedAdminBookingOverrideAuthorizationService(),new BookingRuleContextFingerprint());}
}
