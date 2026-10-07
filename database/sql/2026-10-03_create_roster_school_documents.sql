-- Apply after 2026-10-01_create_roster_final_snapshots.sql. No application-time migration.
CREATE TABLE roster_school_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_type VARCHAR(40) NOT NULL,
  template_version VARCHAR(40) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  filename VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content_bytes MEDIUMBLOB NOT NULL,
  content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_school_document (snapshot_id, document_type),
  CONSTRAINT fk_roster_school_document_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES roster_plan_snapshots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER roster_school_document_no_update BEFORE UPDATE ON roster_school_documents
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final school documents are immutable';
CREATE TRIGGER roster_school_document_no_delete BEFORE DELETE ON roster_school_documents
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final school documents are immutable';
