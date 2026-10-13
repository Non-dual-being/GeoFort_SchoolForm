-- Apply before activating version-aware code. Existing agreements remain unversioned.
-- Never backfill from visit date: future bookings may still promise five modules.
ALTER TABLE aanvragen
    ADD COLUMN program_version VARCHAR(40) NULL DEFAULT NULL AFTER programma;
