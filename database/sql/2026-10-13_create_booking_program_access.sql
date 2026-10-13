-- Preserve the currently available five-module offer until explicitly changed.
CREATE TABLE booking_program_access (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    five_module_open TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_booking_program_access_singleton CHECK (id = 1),
    CONSTRAINT chk_booking_program_access_open CHECK (five_module_open IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO booking_program_access (id, five_module_open) VALUES (1, 1);
