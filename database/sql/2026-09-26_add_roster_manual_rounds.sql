-- GeoFort roosterplanner V8B2C1 - tijdlijn voor zelfstandige roosters.
-- Een ronde is een tijdvak; modules en sessies staan hier los van.
-- NULL blijft de bestaande tijdconfiguratie gebruiken totdat de planner opslaat.

ALTER TABLE roster_manual_plan_context
  ADD COLUMN rounds_json JSON NULL AFTER module_keys_json;
