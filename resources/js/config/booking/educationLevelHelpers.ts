import type {
    AnyGroupKey,
    AnyLevelKey,
    BookingProgramConfigData,
    LevelOptionForAnySector,
    SchoolSectorKey,
} from "../../types/booking/BookingProgramConfigTypes";

export function getLevelOptionsForSector(
    config: BookingProgramConfigData,
    sector: SchoolSectorKey
): LevelOptionForAnySector[] {
     const levelsForSector = config.schoolLevels[sector];

     return Object.entries(levelsForSector).map(([levelKey, levelConfig]) => ({
        key: levelKey as AnyLevelKey,
        label: levelConfig.label as string,
        groups: Object.entries(levelConfig.groups).map(([groupKey, groupLabel]) => ({
        key: groupKey as AnyGroupKey,
        label: groupLabel as string,
    })),
  }));
}