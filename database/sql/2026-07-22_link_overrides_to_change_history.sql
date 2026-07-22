ALTER TABLE booking_rule_overrides
  ADD COLUMN booking_change_history_id BIGINT UNSIGNED NULL AFTER status_history_id,
  ADD KEY idx_booking_rule_overrides_change_history (booking_change_history_id),
  ADD CONSTRAINT fk_booking_rule_overrides_change_history FOREIGN KEY (booking_change_history_id) REFERENCES booking_change_history(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_booking_rule_overrides_exactly_one_history CHECK (
    (status_history_id IS NOT NULL AND booking_change_history_id IS NULL) OR
    (status_history_id IS NULL AND booking_change_history_id IS NOT NULL)
  );
