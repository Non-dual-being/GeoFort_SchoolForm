-- Forward migration for MySQL/MariaDB. DDL causes implicit commits and is NOT atomic.
-- Preflight before execution: verify aanvragen.id is INT, all tables use InnoDB,
-- and no objects with the names below exist with incompatible definitions.
-- Safe resume order after interruption: table, nullable columns, indexes, foreign key.

CREATE TABLE IF NOT EXISTS `legacy_booking_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_system` varchar(80) NOT NULL,
  `source_filename` varchar(255) NOT NULL,
  `source_checksum` char(64) NOT NULL,
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status` enum('running','completed') NOT NULL DEFAULT 'running',
  `imported_count` int unsigned NOT NULL DEFAULT 0,
  `skipped_count` int unsigned NOT NULL DEFAULT 0,
  `warning_count` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_legacy_import_source_checksum` (`source_system`,`source_checksum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MySQL and MariaDB differ in support for ADD ... IF NOT EXISTS. Every DDL step
-- therefore uses information_schema and portable dynamic SQL. The no-op SET
-- keeps PREPARE/EXECUTE valid when the object already exists.

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_system'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD COLUMN `source_system` varchar(80) DEFAULT NULL'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_record_id'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD COLUMN `source_record_id` bigint unsigned DEFAULT NULL'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_record_checksum'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD COLUMN `source_record_checksum` char(64) DEFAULT NULL'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_import_run_id'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD COLUMN `source_import_run_id` bigint unsigned DEFAULT NULL'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND INDEX_NAME='uniq_aanvragen_source_record'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD UNIQUE INDEX `uniq_aanvragen_source_record` (`source_system`,`source_record_id`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND INDEX_NAME='idx_aanvragen_source_import_run'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD INDEX `idx_aanvragen_source_import_run` (`source_import_run_id`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND CONSTRAINT_NAME='fk_aanvragen_source_import_run' AND CONSTRAINT_TYPE='FOREIGN KEY'),
  'SET @migration_noop = 1',
  'ALTER TABLE `aanvragen` ADD CONSTRAINT `fk_aanvragen_source_import_run` FOREIGN KEY (`source_import_run_id`) REFERENCES `legacy_booking_import_runs` (`id`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- If a step fails, correct the reported incompatible object and resume from that
-- step. Do not assume previous DDL was rolled back.
