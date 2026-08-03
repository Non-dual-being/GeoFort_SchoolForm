<?php

declare(strict_types=1);

final readonly class DisposableBookingMariaDb
{
    private function __construct(public PDO $pdo, public string $database,private string $host,private string $port,private string $user,private string $password) {}

    public static function create(string $prefix): self
    {
        if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || strtolower((string)getenv('APP_ENV')) === 'production') throw new RuntimeException('Disposable databasebevestiging ontbreekt.');
        $host=(string)(getenv('STATUS_TEST_DB_HOST')?:'127.0.0.1');$port=(string)(getenv('STATUS_TEST_DB_PORT')?:'3307');$user=(string)(getenv('STATUS_TEST_DB_USER')?:'root');$password=(string)(getenv('STATUS_TEST_DB_PASSWORD')?:'');
        $database='geofort_'.$prefix.'_disposable_test_'.bin2hex(random_bytes(4));
        $server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        self::schema($pdo);
        return new self($pdo,$database,$host,$port,$user,$password);
    }

    public function connect(): PDO
    {
        return new PDO("mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",$this->user,$this->password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }

    public function drop(): void
    {
        if(preg_match('/^geofort_[a-z0-9_]+_disposable_test_[a-f0-9]{8}$/',$this->database)!==1)throw new RuntimeException('Onveilige disposable databasenaam.');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec("DROP DATABASE `{$this->database}`");
    }

    private static function schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE aanvragen (
          id INT NOT NULL AUTO_INCREMENT,status ENUM('In optie','Definitief','Afgewezen') NOT NULL DEFAULT 'In optie',schoolnaam VARCHAR(255) NOT NULL,land VARCHAR(32) NOT NULL,adres VARCHAR(255) NOT NULL,postcode VARCHAR(16) NOT NULL,plaats VARCHAR(120) NOT NULL,
          school_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_voornaam VARCHAR(255) NOT NULL,contactpersoon_achternaam VARCHAR(255) NOT NULL,email VARCHAR(255) NOT NULL,bezoekdatum DATE NOT NULL,
          hoe_kent_u_geofort VARCHAR(120),opmerkingen TEXT,cjpPasGebruik ENUM('ja','nee') NOT NULL DEFAULT 'nee',cjpContactpersoonNaam VARCHAR(80),cjpPasnummer VARCHAR(9),onderwijs_sector VARCHAR(40) NOT NULL,programma VARCHAR(20) NOT NULL,keuzemodule_key VARCHAR(120),
          aantal_leerlingen INT UNSIGNED,aantal_begeleiders INT UNSIGNED,remise_break INT UNSIGNED NOT NULL DEFAULT 0,kazerne_break INT UNSIGNED NOT NULL DEFAULT 0,fortgracht_break INT UNSIGNED NOT NULL DEFAULT 0,glas_limonade INT UNSIGNED NOT NULL DEFAULT 0,waterijsje INT UNSIGNED NOT NULL DEFAULT 0,remise_lunch INT UNSIGNED NOT NULL DEFAULT 0,eigen_picknick TINYINT(1) NOT NULL DEFAULT 0,
          voorwaarden_akkoord TINYINT(1) NOT NULL DEFAULT 0,voorwaarden_akkoord_op DATETIME,source_system VARCHAR(80),source_record_id BIGINT UNSIGNED,source_record_checksum CHAR(64),source_import_run_id BIGINT UNSIGNED,PRIMARY KEY(id),KEY idx_date_status(bezoekdatum,status)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE admin_users(id INT UNSIGNED NOT NULL AUTO_INCREMENT,email VARCHAR(190) NOT NULL,name VARCHAR(120) NOT NULL,role VARCHAR(50) NOT NULL DEFAULT 'admin',password_hash VARCHAR(255) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY(email)) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO admin_users(email,name,password_hash) VALUES('admin@example.test','Admin','test')");
        $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties(id INT NOT NULL AUTO_INCREMENT,aanvraag_id INT NOT NULL,sector_key VARCHAR(40),sector_label VARCHAR(120),level_key VARCHAR(80),level_label VARCHAR(120),level_position INT,group_key VARCHAR(80),group_label VARCHAR(120),group_position INT,PRIMARY KEY(id),FOREIGN KEY(aanvraag_id) REFERENCES aanvragen(id) ON DELETE CASCADE) ENGINE=InnoDB');
        $pdo->exec("CREATE TABLE disabled_dates(datum DATE NOT NULL PRIMARY KEY,type VARCHAR(30),reden VARCHAR(255),source VARCHAR(20) NOT NULL DEFAULT 'generated') ENGINE=InnoDB");
        $pdo->exec('CREATE TABLE form_submit_log(ip_address VARCHAR(45) NOT NULL PRIMARY KEY,submit_time DATETIME NOT NULL) ENGINE=InnoDB');
        $root=dirname(__DIR__,3);
        foreach(['2026-07-17_create_booking_day_settings.sql','2026-07-18_create_booking_status_history.sql','2026-07-21_create_booking_rule_overrides.sql','2026-07-22_create_booking_change_history.sql','2026-07-22_link_overrides_to_change_history.sql','2026-08-03_create_booking_price_snapshots.sql'] as $migration)$pdo->exec((string)file_get_contents($root.'/database/sql/'.$migration));
    }
}
