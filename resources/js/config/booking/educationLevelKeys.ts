import type {
  AnyGroupKey,
  AnyLevelKey,
  LevelKeyForSector,
  LowerSecondaryGroupKey,
  PrimaryGroupKey,
  SchoolSectorKey,
  UpperSecondaryGroupKey,
} from "../../types/booking/BookingProgramConfigTypes";

/**
 * Per onderwijssector welke onderwijsniveaus technisch toegestaan zijn.
 */


export const LEVEL_KEYS_BY_SECTOR = {
  primairOnderwijs: ["regulier", "speciaal"],
  voortgezetOnderbouw: [
    "vmboBasisKader",
    "vmboGemengdTheoretisch",
    "havo",
    "vwo",
    "praktijkOnderwijs",
  ],
  voortgezetBovenbouw: ["vmbo", "havo", "vwo", "praktijkOnderwijs"],
} as const satisfies {
  [S in SchoolSectorKey]: readonly LevelKeyForSector<S>[];
};

/**
 * Type guard: controleert of een level-key hoort bij de opgegeven sector.
 *
 * Belangrijk:
 * - runtime: voorkomt dat verkeerde keys worden opgeslagen
 * - TypeScript: vernauwt AnyLevelKey naar LevelKeyForSector<S>
 */


export function isLevelKeyForSector<S extends SchoolSectorKey>(
  sector: S,
  level: AnyLevelKey,
): level is LevelKeyForSector<S> {
  const allowedLevels = LEVEL_KEYS_BY_SECTOR[sector] as readonly AnyLevelKey[];

  return allowedLevels.includes(level);
}

const PRIMARY_GROUP_KEYS = [
  "groep5",
  "groep6",
  "groep7",
  "groep8",
] as const satisfies readonly PrimaryGroupKey[];

const LOWER_SECONDARY_GROUP_KEYS = [
  "vmbo1",
  "vmbo2",
  "vmbo3",
  "havo1",
  "havo2",
  "havo3",
  "atheneum1",
  "atheneum2",
  "atheneum3",
  "gymnasium1",
  "gymnasium2",
  "gymnasium3",
  "praktijk1",
  "praktijk2",
  "praktijk3",
] as const satisfies readonly LowerSecondaryGroupKey[];

const UPPER_SECONDARY_GROUP_KEYS = [
  "vmbo4",
  "havo4",
  "havo5",
  "atheneum4",
  "atheneum5",
  "atheneum6",
  "gymnasium4",
  "gymnasium5",
  "gymnasium6",
  "praktijk4",
  "praktijk5",
] as const satisfies readonly UpperSecondaryGroupKey[];

/**
 * Type guard voor PO-groepen.
 */
export function isPrimaryGroupKey(
  group: AnyGroupKey,
): group is PrimaryGroupKey {
  return (PRIMARY_GROUP_KEYS as readonly AnyGroupKey[]).includes(group);
}

/**
 * Type guard voor VO-onderbouw-groepen.
 */
export function isLowerSecondaryGroupKey(
  group: AnyGroupKey,
): group is LowerSecondaryGroupKey {
  return (LOWER_SECONDARY_GROUP_KEYS as readonly AnyGroupKey[]).includes(group);
}

/**
 * Type guard voor VO-bovenbouw-groepen.
 */
export function isUpperSecondaryGroupKey(
  group: AnyGroupKey,
): group is UpperSecondaryGroupKey {
  return (UPPER_SECONDARY_GROUP_KEYS as readonly AnyGroupKey[]).includes(group);
}