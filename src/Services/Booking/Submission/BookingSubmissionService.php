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
    )
    {}

    public function submit(BookingRequestData $request, string $clientIp): void 
    {

        try {
            $this->beginTransaction();
            $this->daySettings->lockDate($request->bezoekdatum);
            $visitDate = $this->availability->assertDateIsValid($request->bezoekdatum);
            $this->availability->assertCapacityAvailable($visitDate, $request->aantalLeerlingen, $request->programma);

            $requestId = $this->requestService->insert($request);

            $this->educationSelectionSqlService->insertForRequest(
                $requestId,
                $request->educationSelection,
            );

            $this->bookingMailService->sendRequestReceivedMail($request);
            $this->submitSqlLogService->registerSubmit($clientIp);

            $this->pdo->commit();


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
