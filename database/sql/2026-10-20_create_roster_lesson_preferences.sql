-- Additive: existing lesson grids, staff assignments and settings are unchanged.
CREATE TABLE IF NOT EXISTS roster_plan_lesson_preferences (
    roster_plan_id BIGINT UNSIGNED NOT NULL,
    preferences_json JSON NOT NULL,
    PRIMARY KEY (roster_plan_id),
    CONSTRAINT fk_roster_lesson_preferences_plan
        FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
