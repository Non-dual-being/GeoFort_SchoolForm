-- Additive configuration only: no booking, price or roster rows are changed.
CREATE TABLE booking_program_schedule (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    periods_json LONGTEXT NOT NULL,
    CONSTRAINT chk_program_schedule_singleton CHECK (id = 1),
    CONSTRAINT chk_program_schedule_json CHECK (JSON_VALID(periods_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO booking_program_schedule (id, periods_json) VALUES (1, '[]');

CREATE TABLE booking_program_access_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    revision INT UNSIGNED NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_access_revision (revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
