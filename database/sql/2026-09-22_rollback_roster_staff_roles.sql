DROP TABLE IF EXISTS roster_plan_staffing_settings;

ALTER TABLE roster_staff_members
  DROP COLUMN can_cook,
  DROP COLUMN can_guide,
  DROP COLUMN employment_type;

DELETE FROM roster_staff_module_preferences
WHERE staff_id IN (
  SELECT id FROM roster_staff_members WHERE display_name IN ('Gab Franken', 'Nelleke de With')
);
DELETE FROM roster_staff_members
WHERE display_name IN ('Gab Franken', 'Nelleke de With');
