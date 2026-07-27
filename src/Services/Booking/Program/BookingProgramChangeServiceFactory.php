<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Program;

use GeoFort\Booking\Capacity\{BookingCapacityValidator,PolicyCapacityLimitProvider};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\{BookingValidationCoordinator,StoredBookingProgramValidator,StoredBookingVisitDateValidator};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingProgramSqlRepository,BookingRuleOverrideSqlRepository,DisabledDatesSqlService,StoredBookingSqlRepository};
use PDO;

final readonly class BookingProgramChangeServiceFactory
{
    public function __construct(private PDO $pdo){}
    public function create():BookingProgramChangeService
    {
        $disabled=new DisabledDatesSqlService($this->pdo);
        return new BookingProgramChangeService($this->pdo,new StoredBookingSqlRepository($this->pdo,new StoredBookingAssembler()),new BookingProgramSqlRepository($this->pdo),new BookingChangeHistorySqlRepository($this->pdo),new BookingDaySettingsSqlRepository($this->pdo),new BookingCalendarSqlService($this->pdo),new StoredBookingProgramValidator(),new StoredBookingVisitDateValidator($disabled),new BookingValidationCoordinator(),new PolicyCapacityLimitProvider(),new BookingCapacityValidator(),new BookingRuleOverrideSqlRepository($this->pdo),new BookingRuleOverridePolicy(),new AuthenticatedAdminBookingOverrideAuthorizationService(),new BookingRuleContextFingerprint());
    }
}
