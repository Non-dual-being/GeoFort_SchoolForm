import type {
  AnyGroupKey,
  AnyLevelKey,
  BookingProgramConfigData,
  EducationModuleKey,
  EducationModuleOption,
  ModuleGroupFilterRule,
  ModuleGroupType,
  ProgramKey,
  SchoolSectorKey,
} from "../../types/booking/BookingProgramConfigTypes";

type GetAvailableEducationModuleOptionsParams = {
  config: BookingProgramConfigData;
  sector: SchoolSectorKey;
  program: ProgramKey;
  groupType: ModuleGroupType;
  selectedLevels: AnyLevelKey[];
  selectedGroupsByLevel: Record<string, string[]>;
};

/**
 * Geeft beschikbare modules terug voor:
 * - onderwijssector
 * - programma ochtend/dag
 * - standaard of keuze
 * - gekozen levels
 * - gekozen groepen
 *
 * Belangrijk:
 * config.moduleFilters werkt als uitsluitfilter.
 *
 * Dus:
 * - geen filter voor module = module toegestaan
 * - filter matcht huidige selectie = module NIET toegestaan
 */
export function getAvailableEducationModuleOptions({
  config,
  sector,
  program,
  groupType,
  selectedLevels,
  selectedGroupsByLevel,
}: GetAvailableEducationModuleOptionsParams): EducationModuleOption[] {
  const moduleGroupConfig = config.modules[sector]?.[program];

  if (!moduleGroupConfig) {
    return [];
  }

  const moduleKeys = moduleGroupConfig[groupType] ?? [];

  return moduleKeys
    .filter((moduleKey) =>
      isEducationModuleAllowedByCurrentSelection({
        config,
        moduleKey,
        sector,
        selectedLevels,
        selectedGroupsByLevel,
      }),
    )
    .map((moduleKey) => ({
      key: moduleKey,
      label: getEducationModuleLabel(config, moduleKey),
    }));
}

type IsEducationModuleAllowedParams = {
  config: BookingProgramConfigData;
  moduleKey: EducationModuleKey;
  sector: SchoolSectorKey;
  selectedLevels: AnyLevelKey[];
  selectedGroupsByLevel: Record<string, string[]>;
};

/**
 * Controleert of een module toegestaan is voor de huidige selectie.
 *
 * De backend gebruikt MODULE_FILTERS als uitsluitfilter.
 * Deze frontend-helper moet dat exact hetzelfde doen.
 *
 * Voorbeeld:
 *
 * moduleFilters['Minecraft-Windenergiespeurtocht'] = {
 *   voortgezetOnderbouw: {
 *     vmboBasisKader: '*',
 *     havo: ['havo1']
 *   }
 * }
 *
 * Betekent:
 * - VMBO basis/kader gekozen? Module verbergen.
 * - HAVO 1 gekozen? Module verbergen.
 * - HAVO 2 gekozen? Module mag blijven.
 */
export function isEducationModuleAllowedByCurrentSelection({
  config,
  moduleKey,
  sector,
  selectedLevels,
  selectedGroupsByLevel,
}: IsEducationModuleAllowedParams): boolean {
  return !isEducationModuleExcludedByCurrentSelection({
    config,
    moduleKey,
    sector,
    selectedLevels,
    selectedGroupsByLevel,
  });
}

type IsEducationModuleExcludedParams = {
  config: BookingProgramConfigData;
  moduleKey: EducationModuleKey;
  sector: SchoolSectorKey;
  selectedLevels: AnyLevelKey[];
  selectedGroupsByLevel: Record<string, string[]>;
};

/**
 * True wanneer de module door moduleFilters uitgesloten wordt.
 */
function isEducationModuleExcludedByCurrentSelection({
  config,
  moduleKey,
  sector,
  selectedLevels,
  selectedGroupsByLevel,
}: IsEducationModuleExcludedParams): boolean {
  const moduleFilter = config.moduleFilters[moduleKey];

  /**
   * Geen filter betekent:
   * deze module is nergens uitgesloten.
   */
  if (!moduleFilter) {
    return false;
  }

  const sectorFilter = moduleFilter[sector];

  /**
   * Geen filter voor deze sector betekent:
   * deze module is voor deze sector niet uitgesloten.
   */
  if (!sectorFilter) {
    return false;
  }

  const sectorFilterByLevel = sectorFilter as Partial<
    Record<string, ModuleGroupFilterRule>
  >;

  for (const selectedLevelKey of selectedLevels) {
    const rule = sectorFilterByLevel[selectedLevelKey];

    /**
     * Geen regel voor dit niveau betekent:
     * dit niveau sluit de module niet uit.
     */
    if (!rule) {
      continue;
    }

    /**
     * '*' betekent:
     * zodra dit niveau gekozen is, is de module uitgesloten.
     */
    if (rule === "*") {
      return true;
    }

    const selectedGroupsForLevel =
      selectedGroupsByLevel[selectedLevelKey] ?? [];

    /**
     * Array betekent:
     * module is alleen uitgesloten wanneer één van deze groepen gekozen is.
     */
    const hasExcludedGroup = selectedGroupsForLevel.some((groupKey) =>
      (rule as readonly AnyGroupKey[]).includes(groupKey as AnyGroupKey),
    );

    if (hasExcludedGroup) {
      return true;
    }
  }

  return false;
}

type IsSelectedEducationModuleStillAvailableParams = {
  selectedModule: EducationModuleKey | "";
  availableChoiceModules: readonly EducationModuleOption[];
};

/**
 * Defensieve check voor watchers.
 *
 * Voorbeeld:
 * - gebruiker kiest een module
 * - daarna wijzigt hij groep/level
 * - module is niet meer beschikbaar
 * - selectie moet worden gereset
 */
export function isSelectedEducationModuleStillAvailable({
  selectedModule,
  availableChoiceModules,
}: IsSelectedEducationModuleStillAvailableParams): boolean {
  if (selectedModule === "") {
    return true;
  }

  return availableChoiceModules.some((module) => module.key === selectedModule);
}

function getEducationModuleLabel(
  config: BookingProgramConfigData,
  moduleKey: EducationModuleKey,
): string {
  return config.moduleLabels?.[moduleKey] ?? formatEducationModuleLabel(moduleKey);
}

/**
 * Fallback labelfunctie.
 *
 * Wordt alleen gebruikt als de backend nog geen moduleLabels meestuurt
 * of als er een label mist.
 */
export function formatEducationModuleLabel(moduleKey: EducationModuleKey): string {
  return moduleKey
    .replaceAll("-", " ")
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/\s+/g, " ")
    .trim();
}