<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

final class FormSubmitLogService 
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

     public function getLastSubmitTime(string $ip): ?string {

        $this->assertValidIp($ip);
        try {
            
            $SQL =
            "SELECT 
                submit_time 
            FROM 
                form_submit_log 
            WHERE 
                ip_address = :ip_address 
            ORDER BY 
                submit_time DESC 
            LIMIT 1";

            $stmt = $this->pdo->prepare($SQL);
            $stmt->execute([':ip_address' => $ip]);
            $submitTime = $stmt->fetchColumn();

            return $submitTime === false
                ? null
                : (string) $submitTime;

        } catch (PDOException $e){
            $this->errorLogException($e->getMessage(), __FUNCTION__);
            throw new RuntimeException(
                'Submitlog sql error',
                0,
                $e
            );

        } 
    
    }
    public function registerSubmit(string $ip): bool {
        try {
            $this->assertValidIp($ip);

            $SQL =
            "INSERT INTO 
                form_submit_log (ip_address, submit_time) 
            VALUES 
                (:ip_address, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                submit_time = CURRENT_TIMESTAMP
            ";

            $stmt = $this->pdo->prepare($SQL);
            return $stmt->execute([':ip_address' => $ip]);

       } catch (PDOException $e){
            $this->errorLogException($e->getMessage(), __FUNCTION__);
            throw new RuntimeException(
                'Sumbitlog sql error',
                0,
                $e
            );

        } 

    }

    public function getCoolDownRemaining(string $ip, int $cooldownSec): int {
        $this->assertValidIp($ip);

        if ($cooldownSec <= 0) return 0;

        try {
            $coolDown = 
            "SELECT TIMESTAMPDIFF(
                SECOND,
                submit_time,
                CURRENT_TIMESTAMP
            ) AS seconds_since
            FROM 
                form_submit_log
            WHERE
                ip_address = :ip
            LIMIT
                1            
            ";

            $stmt = $this->pdo->prepare($coolDown);
            $stmt->execute([':ip' => $ip]);
            $since = $stmt->fetchColumn();

            if ($since === false || $since === null){
                return 0;
            }

            $sinceInt = max(0, (int) $since);
            $remaining = max(0, ($cooldownSec - $sinceInt));

            return (int) $remaining;

        } catch (PDOException $e){
            $this->errorLogException($e->getMessage(), __FUNCTION__);
            throw new RuntimeException(
                'Submitlog sql error',
                0,
                $e,
            );
        }
    }

    private function assertValidIp(string $ip): void {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Ongeldige IP-adres');
        }
    }

    private function errorLogException(string $e = '', string $context = ''): void 
    {
        if ($e === '') $e = "unkown error";

        if ($context === '') $context = __CLASS__;

        error_log("[SQL ERROR][$context]: " . $e); 
    }

}
