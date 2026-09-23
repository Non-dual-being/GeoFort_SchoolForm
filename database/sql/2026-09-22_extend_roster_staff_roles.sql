-- GeoFort roosterplanner - personeelsrollen en roosterinstellingen.
-- Vereist:
--   2026-09-22_create_roster_staff_catalog.sql
--   2026-09-22_create_roster_staff_assignments.sql

ALTER TABLE roster_staff_members
  ADD COLUMN employment_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER is_active,
  ADD COLUMN can_guide TINYINT(1) NOT NULL DEFAULT 1 AFTER employment_type,
  ADD COLUMN can_cook TINYINT(1) NOT NULL DEFAULT 0 AFTER can_guide;

CREATE TABLE roster_plan_staffing_settings (
  roster_plan_id BIGINT UNSIGNED NOT NULL,
  staffing_mode VARCHAR(20) NOT NULL DEFAULT 'with_staff',
  prefer_geofort_ke TINYINT(1) NOT NULL DEFAULT 1,
  cook_staff_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (roster_plan_id),
  KEY idx_roster_plan_staffing_cook (cook_staff_id),
  CONSTRAINT fk_roster_plan_staffing_plan
    FOREIGN KEY (roster_plan_id) REFERENCES roster_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_plan_staffing_cook
    FOREIGN KEY (cook_staff_id) REFERENCES roster_staff_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vrijwilligers. Betaald/vrijwilliger is metadata; de optimizer verdeelt werkuren
-- gelijkwaardig en geeft vrijwilligers geen voorkeursbehandeling meer.
UPDATE roster_staff_members
SET employment_type = 'volunteer'
WHERE display_name IN (
  'Frank Duijnhouwer',
  'Frank van Kessel',
  'Piet Visser',
  'Ries Euser',
  'Wiert Ruben'
);

-- Iedereen met VI-vaardigheid kan naast begeleiden ook de kokrol vervullen.
UPDATE roster_staff_members sm
SET can_cook = 1
WHERE EXISTS (
  SELECT 1
  FROM roster_staff_module_preferences pref
  WHERE pref.staff_id = sm.id
    AND pref.module_key = 'Voedsel-Innovatie'
);

-- Gab Franken toevoegen.
INSERT INTO roster_staff_members (
  display_name,
  is_active,
  employment_type,
  can_guide,
  can_cook
) VALUES (
  'Gab Franken',
  1,
  'volunteer',
  1,
  1
)
ON DUPLICATE KEY UPDATE
  is_active = VALUES(is_active),
  employment_type = VALUES(employment_type),
  can_guide = VALUES(can_guide),
  can_cook = VALUES(can_cook);

INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
SELECT id, 'Voedsel-Innovatie', 1 FROM roster_staff_members WHERE display_name = 'Gab Franken'
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);
INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
SELECT id, 'Stop-de-Klimaat-Klok', 2 FROM roster_staff_members WHERE display_name = 'Gab Franken'
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);
INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
SELECT id, 'Klimaat-Experience', 3 FROM roster_staff_members WHERE display_name = 'Gab Franken'
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);
INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
SELECT id, 'Dynamische-Globe', 4 FROM roster_staff_members WHERE display_name = 'Gab Franken'
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);
INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
SELECT id, 'Earth-Watch', 5 FROM roster_staff_members WHERE display_name = 'Gab Franken'
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);

-- Nelleke de With is uitsluitend kok: geen begeleidersvaardigheden.
INSERT INTO roster_staff_members (
  display_name,
  is_active,
  employment_type,
  can_guide,
  can_cook
) VALUES (
  'Nelleke de With',
  1,
  'paid',
  0,
  1
)
ON DUPLICATE KEY UPDATE
  is_active = VALUES(is_active),
  employment_type = VALUES(employment_type),
  can_guide = VALUES(can_guide),
  can_cook = VALUES(can_cook);
