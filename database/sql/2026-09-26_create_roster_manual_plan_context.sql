-- GeoFort roosterplanner V8B1 - basisgegevens voor zelfstandige roosters.
-- Een handmatig rooster gebruikt roster_plans.booking_id = NULL en krijgt
-- zijn brongegevens uit deze tabel. Programma-modules volgen in V8B2.

CREATE TABLE IF NOT EXISTS roster_manual_plan_context (
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  school_name VARCHAR(160) NOT NULL,
  city VARCHAR(120) NOT NULL DEFAULT '',
  sector_key VARCHAR(80) NOT NULL,
  program_key VARCHAR(40) NOT NULL,
  student_count INT UNSIGNED NOT NULL,
  day_start_time TIME NOT NULL,
  day_end_time TIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (roster_plan_id),
  CONSTRAINT fk_roster_manual_plan_context_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
