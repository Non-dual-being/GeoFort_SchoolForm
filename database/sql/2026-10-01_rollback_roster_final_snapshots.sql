-- Administrative rollback only: removing this feature also removes its history.
ALTER TABLE roster_plans DROP FOREIGN KEY fk_roster_published_snapshot;
ALTER TABLE roster_plans DROP INDEX idx_roster_published_snapshot, DROP COLUMN published_snapshot_id;
DROP TABLE roster_plan_lifecycle_events;
DROP TRIGGER IF EXISTS roster_snapshot_no_update;
DROP TRIGGER IF EXISTS roster_snapshot_no_delete;
DROP TABLE roster_plan_snapshots;
