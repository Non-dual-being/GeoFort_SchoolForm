-- Preserve the agreed program when a booking roster becomes a custom roster.
ALTER TABLE roster_manual_plan_context ADD COLUMN program_version VARCHAR(40) NULL AFTER program_key;
