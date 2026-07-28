<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\SchoolContact\BookingSchoolContactDetails;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingSchoolContactSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, BookingSchoolContactDetails $expected, BookingSchoolContactDetails $new): bool
    {
        try {
            $statement=$this->pdo->prepare(<<<'SQL'
                UPDATE aanvragen SET schoolnaam=:newSchoolName,land=:newCountry,adres=:newAddress,
                    postcode=:newPostalCode,plaats=:newCity,school_telefoonnummer=:newSchoolPhone,
                    contactpersoon_voornaam=:newFirstName,contactpersoon_achternaam=:newLastName,
                    email=:newEmail,contactpersoon_telefoonnummer=:newContactPhone
                WHERE id=:id AND schoolnaam=:expectedSchoolName AND land=:expectedCountry
                  AND adres=:expectedAddress AND postcode=:expectedPostalCode AND plaats=:expectedCity
                  AND school_telefoonnummer=:expectedSchoolPhone
                  AND contactpersoon_voornaam=:expectedFirstName
                  AND contactpersoon_achternaam=:expectedLastName AND email=:expectedEmail
                  AND contactpersoon_telefoonnummer=:expectedContactPhone
                SQL);
            $params=[':id'=>$id];
            foreach (['SchoolName'=>'schoolName','Country'=>'country','Address'=>'address','PostalCode'=>'postalCode','City'=>'city','SchoolPhone'=>'schoolPhone','FirstName'=>'contactFirstName','LastName'=>'contactLastName','Email'=>'contactEmail','ContactPhone'=>'contactPhone'] as $sql=>$property) {
                $params[":new{$sql}"]=$new->{$property};$params[":expected{$sql}"]=$expected->{$property};
            }
            $statement->execute($params);
            return $statement->rowCount()===1;
        } catch (PDOException $exception) {
            throw new RuntimeException('School- en contactgegevens konden niet worden gewijzigd.',0,$exception);
        }
    }
}
