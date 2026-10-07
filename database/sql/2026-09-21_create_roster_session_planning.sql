-- GeoFort web roster Run 2.
-- Adds activity sessions and group assignments to the Run 1 roster foundation.

CREATE TABLE IF NOT EXISTS roster_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'activity',
  module_key VARCHAR(120) NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  location_label VARCHAR(160) NULL,
  created_by_admin_id INT UNSIGNED NOT NULL,
  updated_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_roster_sessions_plan_start (roster_plan_id, start_time, end_time),
  KEY idx_roster_sessions_plan_module (roster_plan_id, module_key, start_time),
  CONSTRAINT fk_roster_sessions_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_sessions_created_by
    FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_roster_sessions_updated_by
    FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_session_groups (
  roster_session_id BIGINT UNSIGNED NOT NULL,
  roster_group_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (roster_session_id, roster_group_id),
  KEY idx_roster_session_groups_group (roster_group_id, roster_session_id),
  CONSTRAINT fk_roster_session_groups_session
    FOREIGN KEY (roster_session_id) REFERENCES roster_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_session_groups_group
    FOREIGN KEY (roster_group_id) REFERENCES roster_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;