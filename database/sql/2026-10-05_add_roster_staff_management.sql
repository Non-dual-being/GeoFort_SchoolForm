-- Ronde C: schema only. Existing personnel and immutable roster history remain authoritative.
-- Apply once after the staff catalog/roles and admin_users exist. DDL implicitly commits.
ALTER TABLE roster_staff_members
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD CONSTRAINT chk_roster_staff_version CHECK (version >= 1),
  ADD CONSTRAINT chk_roster_staff_active CHECK (is_active IN (0, 1)),
  ADD CONSTRAINT chk_roster_staff_guide CHECK (can_guide IN (0, 1)),
  ADD CONSTRAINT chk_roster_staff_cook CHECK (can_cook IN (0, 1)),
  ADD CONSTRAINT chk_roster_staff_employment CHECK (employment_type IN ('paid', 'volunteer'));

ALTER TABLE roster_staff_module_preferences
  ADD CONSTRAINT chk_roster_staff_module_rank CHECK (preference_rank BETWEEN 1 AND 255);

CREATE TABLE roster_staff_catalog_state (
  id TINYINT UNSIGNED NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  bootstrap_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  bootstrap_seed_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  bootstrap_completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  CONSTRAINT chk_roster_staff_catalog_singleton CHECK (id = 1),
  CONSTRAINT chk_roster_staff_catalog_revision CHECK (revision >= 1),
  CONSTRAINT chk_roster_staff_bootstrap_status CHECK (bootstrap_status IN ('pending', 'seeded', 'adopted_existing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roster_staff_catalog_state (id, revision, bootstrap_status) VALUES (1, 1, 'pending');

CREATE TABLE roster_staff_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id BIGINT UNSIGNED NOT NULL,
  actor_admin_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(20) NOT NULL,
  previous_version INT UNSIGNED NOT NULL,
  new_version INT UNSIGNED NOT NULL,
  before_json JSON NULL,
  after_json JSON NOT NULL,
  confirmed_impact_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_staff_change_version (staff_id, new_version),
  KEY idx_roster_staff_change_actor (actor_admin_id, id),
  CONSTRAINT fk_roster_staff_change_member FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_staff_change_actor FOREIGN KEY (actor_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_roster_staff_change_action CHECK (action_type IN ('created', 'updated')),
  CONSTRAINT chk_roster_staff_change_version CHECK (new_version = previous_version + 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER roster_staff_no_delete BEFORE DELETE ON roster_staff_members
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Deactivate staff members; roster history must be preserved';
CREATE TRIGGER roster_staff_history_no_update BEFORE UPDATE ON roster_staff_change_history
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff change history is immutable';
CREATE TRIGGER roster_staff_history_no_delete BEFORE DELETE ON roster_staff_change_history
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff change history is immutable';
