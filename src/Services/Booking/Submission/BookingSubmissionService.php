<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Submission;

use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Sql\FormSubmitLogService;
use GeoFort\Services\Sql\EducationSelectionSqlService;
use GeoFort\Services\Sql\RequestService;
use GeoFort\Services\Mail\BookingMailService;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshot;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotService;
use GeoFort\Services\Booking\Pricing\BookingPricingInput;

use PDO;
use RuntimeException;

final class BookingSubmissionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FormSubmitLogService $submitSqlLogService,
        private readonly RequestService $requestService,
        private readonly EducationSelectionSqlService $educationSelectionSqlService,
        private readonly BookingMailService $bookingMailService,
        private readonly BookingDaySettingsSqlRepository $daySettings,
        private readonly BookingAvailabilityService $availability,
        private readonly BookingPriceSnapshotService $priceSnapshots,
    )
    {}

    public function submit(BookingRequestData $request, string $clientIp): BookingSubmissionResult
    {

        try {
            $this->beginTransaction();
            $this->daySettings->lockDate($request->bezoekdatum);
            $visitDate = $this->availability->assertDateIsValid($request->bezoekdatum);
            $this->availability->assertCapacityAvailable($visitDate, $request->aantalLeerlingen, $request->programma);

            $pricingInput = BookingPricingInput::fromRequest($request);
            $requestId = $this->requestService->insert($request);

            $this->educationSelectionSqlService->insertForRequest(
                $requestId,
                $request->educationSelection,
            );

            $storedPriceSnapshot = $this->priceSnapshots->appendUsingActiveVersion(
                $requestId,
                $pricingInput,
                BookingPriceSnapshot::REASON_SUBMISSION,
            );
            $this->submitSqlLogService->registerSubmit($clientIp);

            $this->pdo->commit();
            $mailSent = false;
            try {
                $this->bookingMailService->sendRequestReceivedMail($request, \GeoFort\Services\Booking\Pricing\BookingPriceQuote::fromArray($storedPriceSnapshot->details));
                $mailSent = true;
            } catch (\Throwable $mailException) {
                error_log(sprintf('Aanvraagmail na commit mislukt: exception=%s booking=%d', $mailException::class, $requestId));
            }
            return new BookingSubmissionResult($mailSent);

        } catch(\Throwable $e) {
            if ($this->pdo->inTransaction()){
                $this->pdo->rollBack();
            }
        
            error_log(__METHOD__ . " : " . $e->getMessage());
            
            throw new RuntimeException(
                'Aanvraag niet correct verwerkt',
                0,
                $e
            );
        }


    }

    private function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction();
    }
}
?>
