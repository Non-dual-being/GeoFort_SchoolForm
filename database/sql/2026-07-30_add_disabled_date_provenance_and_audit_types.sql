-- Run after 2026-07-28_create_calendar_date_change_history.sql.
-- MySQL/MariaDB DDL implicitly commits; apply this migration outside application transactions.
ALTER TABLE disabled_dates
  ADD COLUMN source ENUM('generated', 'planner') NOT NULL DEFAULT 'generated' AFTER reden,
  ADD KEY idx_disabled_dates_source_type (source, type);

UPDATE disabled_dates
SET source = CASE WHEN type = 'manual' THEN 'planner' ELSE 'generated' END;

ALTER TABLE calendar_date_change_history_dates
  ADD COLUMN type_before ENUM('manual', 'school_vacation', 'weekend') NULL AFTER manually_blocked_after,
  ADD COLUMN type_after ENUM('manual', 'school_vacation', 'weekend') NULL AFTER type_before;
