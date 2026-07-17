<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

enum StoredBookingIssueCategory: string
{
    case Structural = 'STRUCTURAL';
    case HistoricalConfiguration = 'HISTORICAL_CONFIGURATION';
    case Policy = 'POLICY';
    case Capacity = 'CAPACITY';
    case DisabledDate = 'DISABLED_DATE';
    case HistoricalDate = 'HISTORICAL_DATE';
}
