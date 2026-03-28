<?php
declare(strict_types=1);
namespace GeoFort\DataBase;

use PDO;
use PDOException;
use RuntimeException;

class Connector
{
    private static ?PDO $instance = null;
    private const CHARSET = 'utf8mb4';

    private function __construct(){}
    private function __clone(){}

    private static function normalize(string $text): string
    {
        return trim($text);
    }

    private static function log(string $message, string $context = __CLASS__): void{
        error_log("[$context] : [$message]");
    }

    public static function getConnection(
        string $host,
        string $dbname,
        string $user,
        string $pass,
        string $port
    ): PDO {
        if(self::$instance === null){
            $host = self::normalize($host);
            $dbname = self::normalize($dbname);
            $user = self::normalize($user);
            $port = self::normalize($port);

            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $host,
                $port,
                $dbname,
                self::CHARSET  
            );

            $options = [
                PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION, //throw exeptionss
                PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES      => false                   //use native prepare statements
            ];

            try {
                self::$instance = new PDO(
                    $dsn,
                    $user,
                    $pass,
                    $options
                );

            } catch (PDOException $e){
                self::log($e->getMessage() ?? 'unkown error');
                throw new RuntimeException(
                    "Unable to establish connection",
                    0,
                    $e
                );
            }
        }

        return self::$instance;
    }

}
?>