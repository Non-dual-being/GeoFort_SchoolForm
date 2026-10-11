-- Apply only after backing up the target database. Existing roster contents are unchanged.
CREATE TABLE roster_planning_pairs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 visit_date DATE NOT NULL,
 workflow VARCHAR(20) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by_admin_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 archived_by_admin_id INT UNSIGNED NULL,
 archived_at DATETIME NULL,
 CONSTRAINT fk_roster_pair_creator FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_roster_pair_archiver FOREIGN KEY (archived_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roster_planning_pair_members (
 pair_id BIGINT UNSIGNED NOT NULL,
 plan_id BIGINT UNSIGNED NOT NULL,
 position TINYINT UNSIGNED NOT NULL,
 PRIMARY KEY(pair_id, position),
 UNIQUE KEY uq_roster_pair_member(plan_id),
 CONSTRAINT fk_roster_pair_group FOREIGN KEY(pair_id) REFERENCES roster_planning_pairs(id) ON DELETE RESTRICT,
 CONSTRAINT fk_roster_pair_plan FOREIGN KEY(plan_id) REFERENCES roster_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roster_plan_archives (
 plan_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 pair_id BIGINT UNSIGNED NOT NULL,
 original_booking_id INT NULL,
 presentation_json LONGTEXT NOT NULL,
 archived_by_admin_id INT UNSIGNED NOT NULL,
 archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_roster_archive_plan FOREIGN KEY(plan_id) REFERENCES roster_plans(id) ON DELETE RESTRICT,
 CONSTRAINT fk_roster_archive_pair FOREIGN KEY(pair_id) REFERENCES roster_planning_pairs(id) ON DELETE RESTRICT,
 CONSTRAINT fk_roster_archive_actor FOREIGN KEY(archived_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
