<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingRequestData;
use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

final class RequestService
{
    public function __construct(private PDO $pdo)
    {}

    public function insert(BookingRequestData $request): bool
    {
        try{
            $insert = 
            "INSERT INTO
                aanvragen(schoolnaam)
            VALUES
                (:schoolnaam)
            ";

            $stmt = $this->pdo->prepare($insert);
            return $stmt->execute([
                'schoolnaam' => $request->schoolnaam
            ]);

        } catch (PDOException $e){
            $this->errorLogException($e->getMessage(), __function__);
            throw new RuntimeException(
                'Aanvraag sql error',
                0,
                $e
            );
        }
      

    }

    private function errorLogException(string $e = '', string $context = ''): void 
    {
        if ($e === '') $e = "unkown error";

        if ($context === '') $context = __class__;

        error_log("[SQL ERROR][$context]: " . $e); 
    }
}