-- GeoFort roosterplanner V8B2D1.
-- Separate the booking relation from how the roster is configured.
-- Existing linked plans remain booking_locked; existing unlinked plans become custom.

ALTER TABLE roster_plans
  ADD COLUMN planning_mode ENUM('booking_locked', 'custom') NULL AFTER booking_id;

UPDATE roster_plans
SET planning_mode = CASE
  WHEN booking_id IS NULL THEN 'custom'
  ELSE 'booking_locked'
END
WHERE planning_mode IS NULL;

ALTER TABLE roster_plans
  MODIFY COLUMN planning_mode ENUM('booking_locked', 'custom') NOT NULL;