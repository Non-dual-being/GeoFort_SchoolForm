<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\VisitDate;

use GeoFort\Booking\Capacity\{BookingCapacityValidator,PolicyCapacityLimitProvider};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\{BookingValidationCoordinator,StoredBookingVisitDateValidator};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingRuleOverrideSqlRepository,BookingVisitDateSqlRepository,DisabledDatesSqlService,StoredBookingSqlRepository};
use PDO;

final readonly class BookingVisitDateChangeServiceFactory
{
    public function __construct(private PDO $pdo){}
    public function create():BookingVisitDateChangeService{return new BookingVisitDateChangeService($this->pdo,new StoredBookingSqlRepository($this->pdo,new StoredBookingAssembler()),new BookingVisitDateSqlRepository($this->pdo),new BookingChangeHistorySqlRepository($this->pdo),new BookingDaySettingsSqlRepository($this->pdo),new BookingCalendarSqlService($this->pdo),new StoredBookingVisitDateValidator(new DisabledDatesSqlService($this->pdo)),new BookingValidationCoordinator(),new PolicyCapacityLimitProvider(),new BookingCapacityValidator(),new BookingRuleOverrideSqlRepository($this->pdo),new BookingRuleOverridePolicy(),new AuthenticatedAdminBookingOverrideAuthorizationService(),new BookingRuleContextFingerprint());}
}
