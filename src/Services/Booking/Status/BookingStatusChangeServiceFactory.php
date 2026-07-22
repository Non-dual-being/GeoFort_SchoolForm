<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking\Status;

use GeoFort\Booking\Capacity\BookingCapacityValidator;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Booking\Status\BookingStatusTransitionPolicy;
use GeoFort\Booking\Rules\AuthenticatedAdminBookingOverrideAuthorizationService;
use GeoFort\Booking\Rules\BookingRuleContextFingerprint;
use GeoFort\Booking\Rules\BookingRuleOverridePolicy;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingStatusHistorySqlRepository;
use GeoFort\Services\Sql\BookingStatusSqlRepository;
use GeoFort\Services\Sql\BookingRuleOverrideSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use PDO;

final readonly class BookingStatusChangeServiceFactory
{
    public function __construct(private PDO $pdo, private BookingStatusMailSenderInterface $mailSender) {}

    public function create(): BookingStatusChangeService
    {
        $disabledDates = new DisabledDatesSqlService($this->pdo);

        return new BookingStatusChangeService(
            $this->pdo,
            new StoredBookingSqlRepository($this->pdo, new StoredBookingAssembler()),
            new BookingStatusSqlRepository($this->pdo),
            new BookingStatusHistorySqlRepository($this->pdo),
            new BookingDaySettingsSqlRepository($this->pdo),
            new BookingCalendarSqlService($this->pdo),
            $disabledDates,
            new StoredBookingValidator($disabledDates),
            new BookingStatusTransitionPolicy(),
            new PolicyCapacityLimitProvider(),
            new BookingCapacityValidator(),
            new StoredBookingPricingInputFactory(),
            new BookingPriceCalculator(),
            $this->mailSender,
            new BookingRuleOverrideSqlRepository($this->pdo),
            new BookingRuleOverridePolicy(),
            new AuthenticatedAdminBookingOverrideAuthorizationService(),
            new BookingRuleContextFingerprint(),
        );
    }
}
