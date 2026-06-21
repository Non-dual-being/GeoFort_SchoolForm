// src/config/booking/educationSelectionHelpers.ts

import type {
  AnyGroupKey,
  AnyLevelKey,
  LevelKeyForSector,
  SchoolSectorKey,
} from "../../types/booking/BookingProgramConfigTypes";

import { LEVEL_KEYS_BY_SECTOR } from "./educationLevelKeys";

type LevelSelectionRules = {
  minLevels: number;
  maxLevels: number;
  minGroupsPerLevel: number;
  maxGroupsPerLevel: number;
};

type LevelOption = {
  key: AnyLevelKey;
  groups: {
    key: AnyGroupKey;
  }[];
};

/**
 * Filtert een lijst met levels naar alleen levels die bij de sector horen.
 *
 * Deze functie is puur:
 * - geen Vue
 * - geen formValues
 * - geen side effects
 */
export function filterValidLevelsForSector<S extends SchoolSectorKey>(
  sector: S,
  levels: AnyLevelKey[],
): LevelKeyForSector<S>[] {
  const allowedLevels = LEVEL_KEYS_BY_SECTOR[sector] as readonly AnyLevelKey[];

  return levels.filter((level): level is LevelKeyForSector<S> =>
    allowedLevels.includes(level),
  );
}

/**
 * Haalt uit de beschikbare level-opties welke groepen bij één level horen.
 */
export function getAllowedGroupKeysForLevel(
  levelKey: AnyLevelKey,
  availableLevels: readonly LevelOption[],
): Set<AnyGroupKey> {
  const levelOption = availableLevels.find((level) => level.key === levelKey);

  if (!levelOption) {
    return new Set();
  }

  return new Set(levelOption.groups.map((group) => group.key));
}

/**
 * Filtert gekozen groepen naar groepen die volgens de config bij dit level horen.
 */
export function filterValidGroupsForLevel(
  levelKey: AnyLevelKey,
  groups: string[],
  availableLevels: readonly LevelOption[],
): AnyGroupKey[] {
  const allowedGroupKeys = getAllowedGroupKeysForLevel(
    levelKey,
    availableLevels,
  );

  return groups.filter((group): group is AnyGroupKey =>
    allowedGroupKeys.has(group as AnyGroupKey),
  );
}

/**
 * Valideert alleen het aantal gekozen onderwijsniveaus.
 *
 * Geeft null terug wanneer de selectie geldig is.
 * Geeft een string terug wanneer er een gebruikersmelding nodig is.
 */
export function getLevelSelectionIssue(
  selectedLevelCount: number,
  rules: LevelSelectionRules,
): string | null {
  if (selectedLevelCount < rules.minLevels) {
    return rules.minLevels === 1
      ? "Kies minimaal één onderwijsniveau."
      : `Kies minimaal ${rules.minLevels} onderwijsniveaus.`;
  }

  if (selectedLevelCount > rules.maxLevels) {
    return rules.maxLevels === 1
      ? "Kies maximaal één onderwijsniveau."
      : `Kies maximaal ${rules.maxLevels} onderwijsniveaus.`;
  }

  return null;
}

/**
 * Valideert per geselecteerd level of:
 * - alle gekozen groepen bij dat level horen
 * - minimaal genoeg groepen gekozen zijn
 * - niet te veel groepen gekozen zijn
 */
export function getGroupSelectionIssues(params: {
  selectedLevels: AnyLevelKey[];
  groupsByLevel: Record<string, string[]>;
  availableLevels: readonly LevelOption[];
  rules: LevelSelectionRules;
}): Record<string, string> {
  const issues: Record<string, string> = {};

  for (const levelKey of params.selectedLevels) {
    const selectedGroups = params.groupsByLevel[levelKey] ?? [];
    const allowedGroups = getAllowedGroupKeysForLevel(
      levelKey,
      params.availableLevels,
    );

    const invalidGroups = selectedGroups.filter(
      (group) => !allowedGroups.has(group as AnyGroupKey),
    );

    if (invalidGroups.length > 0) {
      issues[levelKey] =
        "Er is een groep of leerjaar gekozen die niet bij dit onderwijsniveau hoort.";
      continue;
    }

    if (selectedGroups.length < params.rules.minGroupsPerLevel) {
      issues[levelKey] =
        "Kies minimaal één groep of leerjaar bij dit niveau.";
      continue;
    }

    if (selectedGroups.length > params.rules.maxGroupsPerLevel) {
      issues[levelKey] =
        `Kies maximaal ${params.rules.maxGroupsPerLevel} groepen of leerjaren bij dit niveau.`;
    }
  }

  return issues;
}