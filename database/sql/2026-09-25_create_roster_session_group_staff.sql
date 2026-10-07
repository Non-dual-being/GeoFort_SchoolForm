-- GeoFort roosterplanner - groepsspecifieke begeleiders binnen een gedeelde sessie.
-- De bestaande roster_session_staff blijft de geaggregeerde unie voor conflictcontroles.

CREATE TABLE IF NOT EXISTS roster_session_group_staff (
  roster_session_id BIGINT UNSIGNED NOT NULL,
  roster_group_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  assignment_source VARCHAR(20) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (roster_session_id, roster_group_id, staff_id),
  KEY idx_roster_session_group_staff_staff (staff_id, roster_session_id, roster_group_id),
  CONSTRAINT fk_roster_session_group_staff_link
    FOREIGN KEY (roster_session_id, roster_group_id)
    REFERENCES roster_session_groups(roster_session_id, roster_group_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_roster_session_group_staff_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
