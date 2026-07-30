-- Destructive metadata rollback: provenance and typed audit detail are removed.
ALTER TABLE calendar_date_change_history_dates
  DROP COLUMN type_after,
  DROP COLUMN type_before;

ALTER TABLE disabled_dates
  DROP KEY idx_disabled_dates_source_type,
  DROP COLUMN source;
