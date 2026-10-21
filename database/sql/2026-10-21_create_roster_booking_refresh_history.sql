-- Records the previous state before an explicitly confirmed booking refresh.
CREATE TABLE IF NOT EXISTS roster_booking_refresh_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 roster_plan_id BIGINT UNSIGNED NOT NULL,
 source_revision INT UNSIGNED NOT NULL,
 before_json LONGTEXT NOT NULL,
 changes_json LONGTEXT NOT NULL,
 created_by_admin_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_roster_refresh_plan FOREIGN KEY(roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
 CONSTRAINT fk_roster_refresh_actor FOREIGN KEY(created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
