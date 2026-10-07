-- GeoFort roosterplanner V8B2B - gekozen modules voor zelfstandige roosters.
-- De volgorde in deze JSON-array is de planner-volgorde voor de dagindeling.

ALTER TABLE roster_manual_plan_context
  ADD COLUMN module_keys_json JSON NULL AFTER student_count;
