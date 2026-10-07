-- Explicit supervision for each group in a shared session. NULL is legacy/unconfirmed.
ALTER TABLE roster_session_groups
  ADD COLUMN supervision_kind ENUM('staff', 'school', 'open') NULL;
