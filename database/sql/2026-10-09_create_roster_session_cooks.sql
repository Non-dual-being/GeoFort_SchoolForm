-- Optional per-session override; absent rows retain the school's original cook.
CREATE TABLE IF NOT EXISTS roster_session_cooks (
 roster_session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_roster_session_cook_session FOREIGN KEY(roster_session_id) REFERENCES roster_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_roster_session_cook_staff FOREIGN KEY(staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
