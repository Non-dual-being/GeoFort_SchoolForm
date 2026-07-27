<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\ProgramConfiguration;

use GeoFort\Booking\Capacity\{BookingCapacityValidator,PolicyCapacityLimitProvider};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingProgramConfigurationSqlRepository,BookingRuleOverrideSqlRepository,StoredBookingSqlRepository};
use PDO;

final readonly class BookingProgramConfigurationServiceFactory
{
    public function __construct(private PDO $pdo) {}
    public function create(): BookingProgramConfigurationService
    {
        return new BookingProgramConfigurationService(
            $this->pdo,
            new StoredBookingSqlRepository($this->pdo,new StoredBookingAssembler()),
            new BookingProgramConfigurationSqlRepository($this->pdo),
            new BookingChangeHistorySqlRepository($this->pdo),
            new BookingRuleOverrideSqlRepository($this->pdo),
            new BookingDaySettingsSqlRepository($this->pdo),
            new BookingCalendarSqlService($this->pdo),
            new PolicyCapacityLimitProvider(),
            new BookingCapacityValidator(),
        );
    }
}
