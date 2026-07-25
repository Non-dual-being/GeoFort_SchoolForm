<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Booking\Catering\BookingCateringValues;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingCateringSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, BookingCateringValues $expected, BookingCateringValues $new): bool
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE aanvragen SET
                    remise_break=:newRemiseBreak,kazerne_break=:newKazerneBreak,
                    fortgracht_break=:newFortgrachtBreak,waterijsje=:newWaterIce,
                    glas_limonade=:newLemonade,remise_lunch=:newRemiseLunch,
                    eigen_picknick=:newOwnPicnic
                WHERE id=:id AND remise_break=:expectedRemiseBreak
                  AND kazerne_break=:expectedKazerneBreak AND fortgracht_break=:expectedFortgrachtBreak
                  AND waterijsje=:expectedWaterIce AND glas_limonade=:expectedLemonade
                  AND remise_lunch=:expectedRemiseLunch AND eigen_picknick=:expectedOwnPicnic
                SQL);
            $params = [
                ':id'=>$id,
                ':newRemiseBreak'=>$new->remiseBreak, ':newKazerneBreak'=>$new->kazerneBreak,
                ':newFortgrachtBreak'=>$new->fortgrachtBreak, ':newWaterIce'=>$new->waterIce,
                ':newLemonade'=>$new->lemonade, ':newRemiseLunch'=>$new->remiseLunch,
                ':newOwnPicnic'=>$new->ownPicnic ? 1 : 0,
                ':expectedRemiseBreak'=>$expected->remiseBreak, ':expectedKazerneBreak'=>$expected->kazerneBreak,
                ':expectedFortgrachtBreak'=>$expected->fortgrachtBreak, ':expectedWaterIce'=>$expected->waterIce,
                ':expectedLemonade'=>$expected->lemonade, ':expectedRemiseLunch'=>$expected->remiseLunch,
                ':expectedOwnPicnic'=>$expected->ownPicnic ? 1 : 0,
            ];
            foreach ($params as $key => $value) $statement->bindValue($key, $value, PDO::PARAM_INT);
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException('Cateringgegevens konden niet worden gewijzigd.', 0, $exception);
        }
    }
}
