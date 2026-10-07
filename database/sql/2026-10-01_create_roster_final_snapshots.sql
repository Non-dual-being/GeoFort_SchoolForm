-- Apply after the roster foundation, session/group supervision and admin_users exist.
CREATE TABLE roster_plan_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  source_revision INT UNSIGNED NOT NULL,
  published_revision INT UNSIGNED NOT NULL,
  format_version SMALLINT UNSIGNED NOT NULL,
  content_json JSON NOT NULL,
  content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  finalized_by_admin_id INT UNSIGNED NOT NULL,
  finalized_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_snapshot_revision (roster_plan_id, published_revision),
  UNIQUE KEY uq_roster_snapshot_owner (roster_plan_id, id),
  CONSTRAINT fk_roster_snapshot_plan FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_snapshot_actor FOREIGN KEY (finalized_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE roster_plans
  ADD COLUMN published_snapshot_id BIGINT UNSIGNED NULL,
  ADD KEY idx_roster_published_snapshot (id, published_snapshot_id),
  ADD CONSTRAINT fk_roster_published_snapshot
    FOREIGN KEY (id, published_snapshot_id) REFERENCES roster_plan_snapshots(roster_plan_id, id) ON DELETE RESTRICT;

CREATE TABLE roster_plan_lifecycle_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  snapshot_id BIGINT UNSIGNED NULL,
  event_type ENUM('finalized', 'reopened') NOT NULL,
  previous_status VARCHAR(20) NOT NULL,
  new_status VARCHAR(20) NOT NULL,
  previous_revision INT UNSIGNED NOT NULL,
  new_revision INT UNSIGNED NOT NULL,
  actor_admin_id INT UNSIGNED NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_roster_lifecycle_plan (roster_plan_id, id),
  CONSTRAINT fk_roster_lifecycle_plan FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_lifecycle_snapshot FOREIGN KEY (snapshot_id) REFERENCES roster_plan_snapshots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_lifecycle_actor FOREIGN KEY (actor_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER roster_snapshot_no_update BEFORE UPDATE ON roster_plan_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final roster snapshots are immutable';
CREATE TRIGGER roster_snapshot_no_delete BEFORE DELETE ON roster_plan_snapshots
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final roster snapshots are immutable';
