-- Tracking-model rollback only. DDL is NOT transactional.
-- First rollback every import-run with scripts/rollback-legacy-booking-import.php.
-- This preflight intentionally aborts while any imported aanvraag remains.
-- All conditional drops use information_schema instead of engine-specific
-- DROP ... IF EXISTS variants, so interrupted cleanup can also be resumed.

DELIMITER //
DROP PROCEDURE IF EXISTS `_migration_assert_no_legacy_bookings`//
CREATE PROCEDURE `_migration_assert_no_legacy_bookings`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_system'
  ) THEN
    IF EXISTS (SELECT 1 FROM `aanvragen` WHERE `source_system` IS NOT NULL LIMIT 1) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Tracking rollback geblokkeerd: rollback eerst alle legacy import-runs.';
    END IF;
  END IF;
END//
CALL `_migration_assert_no_legacy_bookings`()//
DROP PROCEDURE `_migration_assert_no_legacy_bookings`//
DELIMITER ;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND CONSTRAINT_NAME='fk_aanvragen_source_import_run' AND CONSTRAINT_TYPE='FOREIGN KEY'),
  'ALTER TABLE `aanvragen` DROP FOREIGN KEY `fk_aanvragen_source_import_run`',
  'SET @migration_noop = 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND INDEX_NAME='idx_aanvragen_source_import_run'),
  'ALTER TABLE `aanvragen` DROP INDEX `idx_aanvragen_source_import_run`',
  'SET @migration_noop = 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND INDEX_NAME='uniq_aanvragen_source_record'),
  'ALTER TABLE `aanvragen` DROP INDEX `uniq_aanvragen_source_record`',
  'SET @migration_noop = 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_import_run_id'),'ALTER TABLE `aanvragen` DROP COLUMN `source_import_run_id`','SET @migration_noop = 1');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_record_checksum'),'ALTER TABLE `aanvragen` DROP COLUMN `source_record_checksum`','SET @migration_noop = 1');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_record_id'),'ALTER TABLE `aanvragen` DROP COLUMN `source_record_id`','SET @migration_noop = 1');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aanvragen' AND COLUMN_NAME='source_system'),'ALTER TABLE `aanvragen` DROP COLUMN `source_system`','SET @migration_noop = 1');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_booking_import_runs'),
  'DROP TABLE `legacy_booking_import_runs`',
  'SET @migration_noop = 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
