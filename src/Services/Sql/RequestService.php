<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

use GeoFort\Services\Booking\BookingRequestData;

final class RequestService
{
    public function __construct(private PDO $pdo)
    {}

    public function insert(BookingRequestData $request): bool
    {
        try{
            $insert = 
            "INSERT INTO
                aanvragen(
                    schoolnaam,
                    land, 
                    adres,
                    postcode,
                    plaats,
                    school_telefoonnummer,
                    contactpersoon_telefoonnummer,
                    contactpersoon_voornaam,
                    contactpersoon_achternaam,
                    email,
                    bezoekdatum
                )
            VALUES (
                :schoolnaam,
                :land,
                :adres,
                :postcode,
                :plaats,
                :schoolTelefoonnummer,
                :contactpersoonTelefoonnummer,
                :contactpersoonVoornaam,
                :contactpersoonAchternaam,
                :email,
                :bezoekdatum
                )
            ";

            $stmt = $this->pdo->prepare($insert);
            return $stmt->execute([
                ':schoolnaam'                   => $request->schoolnaam,
                ':land'                         => $request->land,
                ':adres'                        => $request->adres,
                ':postcode'                     => $request->postcode,
                ':plaats'                       => $request->plaats,
                ':schoolTelefoonnummer'         => $request->schoolTelefoonnummer,
                ':contactpersoonTelefoonnummer' => $request->contactpersoonTelefoonnummer,
                ':contactpersoonVoornaam'       => $request->contactpersoonVoornaam,        
                ':contactpersoonAchternaam'     => $request->contactpersoonAchternaam,
                ':email'                        => $request->email,        
                ':bezoekdatum'                  => $request->bezoekdatum        
            ]);

        } catch (PDOException $e){
            $this->errorLogException($e->getMessage(), __FUNCTION__);
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

        if ($context === '') $context = __CLASS__;

        error_log("[SQL ERROR][$context]: " . $e); 
    }
}