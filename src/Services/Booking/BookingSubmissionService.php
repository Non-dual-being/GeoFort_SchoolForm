<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking;

use GeoFort\Services\Sql\FormSubmitLogService;
use GeoFort\Services\Sql\RequestService;
use PDO;
use RuntimeException;

final class BookingSubmissionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FormSubmitLogService $submitLogService,
        private readonly RequestService $requestService,
    )
    {}

    public function submit(BookingRequestData $request, string $clientIp): void 
    {

        try {
            $this->beginTransaction();
            $this->RequestService->insert($request);
            $this->submitLogService->registerSubmit($clientip);
            $this->pdo->commit();


        } catch(\Throwable $e) {
            if ($this->pdo->inTransaction()){
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                'Aanvraag niet correct verwerkt',
                0,
                $e
            );
        }


    }

    private function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction) $this->pdo->beginTransaction();
    }
}
?>