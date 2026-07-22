-- Attendance override audits cannot exist without their change-history owner.
DELETE FROM booking_rule_overrides WHERE booking_change_history_id IS NOT NULL;

ALTER TABLE booking_rule_overrides
  DROP CONSTRAINT chk_booking_rule_overrides_exactly_one_history,
  DROP FOREIGN KEY fk_booking_rule_overrides_change_history,
  DROP INDEX idx_booking_rule_overrides_change_history,
  DROP COLUMN booking_change_history_id;
