-- Additive only. No consent is inferred for existing requests. Sending starts disabled.
CREATE TABLE IF NOT EXISTS visit_survey_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 delay_days TINYINT UNSIGNED NOT NULL DEFAULT 2,
 start_date DATE NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by INT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO visit_survey_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS visit_survey_consents (
 booking_id INT NOT NULL PRIMARY KEY,
 email_hash CHAR(64) NOT NULL,
 wording_version VARCHAR(40) NOT NULL,
 granted_at DATETIME NOT NULL,
 withdrawn_at DATETIME NULL,
 excluded TINYINT(1) NOT NULL DEFAULT 0,
 excluded_by INT UNSIGNED NULL,
 CONSTRAINT fk_visit_consent_booking FOREIGN KEY (booking_id) REFERENCES aanvragen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_survey_invitations (
 booking_id INT NOT NULL PRIMARY KEY,
 token_hash CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 attempted_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY uq_visit_survey_token (token_hash),
 CONSTRAINT fk_visit_invitation_consent FOREIGN KEY (booking_id) REFERENCES visit_survey_consents(booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_survey_responses (
 booking_id INT NOT NULL PRIMARY KEY,
 satisfaction VARCHAR(20) NOT NULL,
 favorite_module VARCHAR(120) NULL,
 tip TEXT NOT NULL,
 answered_at DATETIME NOT NULL,
 CONSTRAINT fk_visit_response_invitation FOREIGN KEY (booking_id) REFERENCES visit_survey_invitations(booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
