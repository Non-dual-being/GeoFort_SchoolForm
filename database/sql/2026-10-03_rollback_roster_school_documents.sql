-- DESTRUCTIVE schema rollback for empty/disposable installations only.
-- Export/retain document history before an explicitly approved production schema rollback.
-- Never call this from an application workflow.
DROP TRIGGER IF EXISTS roster_school_document_no_update;
DROP TRIGGER IF EXISTS roster_school_document_no_delete;
DROP TABLE IF EXISTS roster_school_documents;
