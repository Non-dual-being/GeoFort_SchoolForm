CREATE TABLE booking_price_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  sequence_number INT UNSIGNED NOT NULL,
  previous_snapshot_id BIGINT UNSIGNED NULL,
  snapshot_reason VARCHAR(40) NOT NULL,
  calculation_state VARCHAR(40) NOT NULL,
  pricing_version VARCHAR(80) NULL,
  currency_code CHAR(3) NULL,
  vat_meaning VARCHAR(40) NULL,
  vat_basis_points SMALLINT UNSIGNED NULL,
  visit_amount_incl_vat_cents BIGINT UNSIGNED NULL,
  catering_amount_incl_vat_cents BIGINT UNSIGNED NULL,
  total_amount_incl_vat_cents BIGINT UNSIGNED NULL,
  total_amount_excl_vat_cents BIGINT UNSIGNED NULL,
  vat_amount_cents BIGINT UNSIGNED NULL,
  canonical_input_json JSON NOT NULL,
  calculation_details_json JSON NOT NULL,
  input_checksum CHAR(64) NOT NULL,
  checksum_format_version SMALLINT UNSIGNED NOT NULL,
  created_by_admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_price_snapshots_booking_sequence (booking_id, sequence_number),
  KEY idx_booking_price_snapshots_booking_latest (booking_id, sequence_number),
  KEY idx_booking_price_snapshots_previous (previous_snapshot_id),
  CONSTRAINT fk_booking_price_snapshots_booking FOREIGN KEY (booking_id) REFERENCES aanvragen (id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_price_snapshots_previous FOREIGN KEY (previous_snapshot_id) REFERENCES booking_price_snapshots (id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_price_snapshots_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_booking_price_snapshots_state CHECK (calculation_state IN ('complete', 'historical_price_unavailable', 'invalid_input')),
  CONSTRAINT chk_booking_price_snapshots_reason CHECK (snapshot_reason IN ('submission', 'planner_update', 'confirmation', 'legacy_manual_acceptance', 'legacy_unavailable_at_confirmation')),
  CONSTRAINT chk_booking_price_snapshots_complete CHECK (
    (calculation_state = 'complete'
      AND pricing_version IS NOT NULL AND currency_code IS NOT NULL AND vat_meaning IS NOT NULL AND vat_basis_points IS NOT NULL
      AND visit_amount_incl_vat_cents IS NOT NULL AND catering_amount_incl_vat_cents IS NOT NULL
      AND total_amount_incl_vat_cents IS NOT NULL AND total_amount_excl_vat_cents IS NOT NULL AND vat_amount_cents IS NOT NULL
      AND visit_amount_incl_vat_cents + catering_amount_incl_vat_cents = total_amount_incl_vat_cents
      AND total_amount_excl_vat_cents + vat_amount_cents = total_amount_incl_vat_cents)
    OR
    (calculation_state <> 'complete'
      AND visit_amount_incl_vat_cents IS NULL AND catering_amount_incl_vat_cents IS NULL
      AND total_amount_incl_vat_cents IS NULL AND total_amount_excl_vat_cents IS NULL AND vat_amount_cents IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
