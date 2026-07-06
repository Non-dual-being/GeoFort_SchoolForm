<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

use GeoFort\Services\Booking\Data\BookingRequestData;

final class RequestService
{
    public function __construct(private PDO $pdo)
    {}

    public function insert(BookingRequestData $request): int
{
    try {
        $insert = "
            INSERT INTO aanvragen (
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
                bezoekdatum,
                cjpPasGebruik,
                cjpContactpersoonNaam,
                cjpPasnummer,
                onderwijs_sector,
                programma,
                keuzemodule_key,
                aantal_leerlingen,
                aantal_begeleiders
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
                :bezoekdatum,
                :cjpPasGebruik,
                :cjpContactpersoonNaam,
                :cjpPasnummer,
                :onderwijsSector,
                :programma,
                :keuzemoduleKey,
                :aantalLeerlingen,
                :aantalBegeleiders
            )
        ";

        $stmt = $this->pdo->prepare($insert);

        $stmt->execute([
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
            ':bezoekdatum'                  => $request->bezoekdatum,
            ':cjpPasGebruik'                => $request->cjpPasGebruik,
            ':cjpContactpersoonNaam'        => $request->cjpContactpersoonNaam,
            ':cjpPasnummer'                 => $request->cjpPasnummer,
            ':onderwijsSector'              => $request->schoolSector,
            ':programma'                    => $request->programma,
            ':keuzemoduleKey'               => $request->keuzemoduleKey,
            ':aantalLeerlingen'             => $request->aantalLeerlingen,
            ':aantalBegeleiders'            => $request->aantalBegeleiders,
        ]);

        return (int) $this->pdo->lastInsertId();
    } catch (PDOException $e) {
        $this->errorLogException($e->getMessage(), __FUNCTION__);

        throw new RuntimeException(
            'Aanvraag sql error',
            0,
            $e,
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
