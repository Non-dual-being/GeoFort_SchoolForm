// src/composables/useEducationSelection.ts

import { computed, type ComputedRef, type Ref } from "vue";

import type {
  AnyGroupKey,
  AnyLevelKey,
  BookingProgramConfigData,
  LowerSecondaryLevelKey,
  PrimaryLevelKey,
  SchoolSectorKey,
  UpperSecondaryLevelKey,
} from "../types/booking/BookingProgramConfigTypes";

import type { BookingFormValues } from "../config/booking/BookingFields";

import { getLevelOptionsForSector } from "../config/booking/educationLevelHelpers";

import {
  filterValidGroupsForLevel,
  getGroupSelectionIssues,
  getLevelSelectionIssue,
} from "../config/booking/educationSelectionHelpers";

import {
  isLevelKeyForSector,
  isLowerSecondaryGroupKey,
  isPrimaryGroupKey,
  isUpperSecondaryGroupKey,
} from "../config/booking/educationLevelKeys";

type UseEducationSelectionParams = {
  formValues: Ref<BookingFormValues>;
  bookingProgramConfig: Ref<BookingProgramConfigData | null>;
  currentSchoolSector: ComputedRef<SchoolSectorKey | null>;
  canShowEducationDetails: ComputedRef<boolean>;
};

export function useEducationSelection(params: UseEducationSelectionParams) {
  /**
   * Beschikbare levels voor de actuele onderwijssector.
   *
   * Komt uit de backend/config-data, niet hardcoded uit de component.
   */
  const availableLevelsForSector = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;

    if (!config || !sector) {
      return [];
    }

    return getLevelOptionsForSector(config, sector);
  });

  /**
   * Selectieregels voor de actuele onderwijssector.
   *
   * Bijvoorbeeld:
   * - minimaal 1 niveau
   * - maximaal 3 niveaus
   * - minimaal 1 groep per niveau
   */
  const currentLevelSelectionRules = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;

    if (!config || !sector) {
      return null;
    }

    return config.schoolLevelSelectionRules[sector];
  });

  /**
   * Writable computed voor de geselecteerde levels van de huidige sector.
   *
   * De template/component hoeft daardoor niet te weten of het om PO,
   * VO onderbouw of VO bovenbouw gaat.
   */
  const selectedLevelsForCurrentSector = computed<AnyLevelKey[]>({
    get() {
      const sector = params.currentSchoolSector.value;

      if (!sector) {
        return [];
      }

      return params.formValues.value.levelSelection.selectedLevels[sector];
    },

    set(nextLevels) {
      const sector = params.currentSchoolSector.value;

      if (!sector) {
        return;
      }

      setSelectedLevelsForSector(sector, nextLevels);
    },
  });

  /**
   * Writable computed voor de geselecteerde groepen van de huidige sector.
   *
   * De getter geeft kopieën terug van de arrays.
   * Daardoor voorkom je dat een child-component per ongeluk direct
   * nested form-state muteert buiten de setter om.
   */
  const selectedGroupsForCurrentSector = computed<Record<string, string[]>>({
    get() {
      const sector = params.currentSchoolSector.value;

      if (!sector) {
        return {};
      }

      return Object.fromEntries(
        Object.entries(
          params.formValues.value.levelSelection.selectedGroupsByLevel[sector],
        ).map(([levelKey, groups]) => [levelKey, [...groups]]),
      );
    },

    set(nextGroupsByLevel) {
      const sector = params.currentSchoolSector.value;

      if (!sector) {
        return;
      }

      setSelectedGroupsForSector(sector, nextGroupsByLevel);
    },
  });

  /**
   * Valideert de levelselectie van de actuele sector.
   */
  const levelSelectionIssue = computed<string | null>(() => {
    const rules = currentLevelSelectionRules.value;

    if (
      !rules ||
      !params.currentSchoolSector.value ||
      !params.canShowEducationDetails.value
    ) {
      return null;
    }

    return getLevelSelectionIssue(
      selectedLevelsForCurrentSector.value.length,
      rules,
    );
  });

  /**
   * True wanneer de gekozen onderwijsniveaus voldoen aan de regels.
   */
  const hasValidLevelSelection = computed(() => {
    return levelSelectionIssue.value === null;
  });

  /**
   * Valideert per gekozen level de bijbehorende groep/leerjaarselectie.
   */
  const groupSelectionIssues = computed<Record<string, string>>(() => {
    const rules = currentLevelSelectionRules.value;

    if (!rules || !params.canShowEducationDetails.value) {
      return {};
    }

    return getGroupSelectionIssues({
      selectedLevels: selectedLevelsForCurrentSector.value,
      groupsByLevel: selectedGroupsForCurrentSector.value,
      availableLevels: availableLevelsForSector.value,
      rules,
    });
  });

  /**
   * True wanneer:
   * - minimaal één level gekozen is
   * - elk gekozen level een geldige groepselectie heeft
   */
  const hasValidGroupSelection = computed(() => {
    return (
      selectedLevelsForCurrentSector.value.length > 0 &&
      Object.keys(groupSelectionIssues.value).length === 0
    );
  });

  /**
   * Verwijdert groepen van levels die inmiddels niet meer geselecteerd zijn.
   *
   * Voorbeeld:
   * - gebruiker kiest havo
   * - gebruiker kiest havo1
   * - gebruiker vinkt havo uit
   * - havo1 moet dan ook uit de form-state verdwijnen
   */
  function pruneGroupsForDeselectedLevels(): void {
    const sector = params.currentSchoolSector.value;

    if (!sector) {
      return;
    }

    const selectedLevelSet = new Set(selectedLevelsForCurrentSector.value);

    const groupsByLevel =
      params.formValues.value.levelSelection.selectedGroupsByLevel[sector];

    for (const levelKey of Object.keys(groupsByLevel)) {
      if (!selectedLevelSet.has(levelKey as AnyLevelKey)) {
        groupsByLevel[levelKey as keyof typeof groupsByLevel] = [] as never;
      }
    }
  }

  /**
   * Slaat levels op voor de juiste sector.
   *
   * Deze functie filtert defensief:
   * verkeerde keys worden niet opgeslagen.
   */
  function setSelectedLevelsForSector(
    sector: SchoolSectorKey,
    nextLevels: AnyLevelKey[],
  ): void {
    if (sector === "primairOnderwijs") {
      params.formValues.value.levelSelection.selectedLevels.primairOnderwijs =
        nextLevels.filter((level): level is PrimaryLevelKey =>
          isLevelKeyForSector("primairOnderwijs", level),
        );

      return;
    }

    if (sector === "voortgezetOnderbouw") {
      params.formValues.value.levelSelection.selectedLevels.voortgezetOnderbouw =
        nextLevels.filter((level): level is LowerSecondaryLevelKey =>
          isLevelKeyForSector("voortgezetOnderbouw", level),
        );

      return;
    }

    params.formValues.value.levelSelection.selectedLevels.voortgezetBovenbouw =
      nextLevels.filter((level): level is UpperSecondaryLevelKey =>
        isLevelKeyForSector("voortgezetBovenbouw", level),
      );
  }

  /**
   * Slaat groepen op voor de juiste sector.
   *
   * Deze functie doet drie dingen:
   * 1. levels die niet geselecteerd zijn krijgen lege groepen
   * 2. groepen worden gecontroleerd tegen de level-config
   * 3. groepen worden vernauwd naar het correcte sectortype
   */
  function setSelectedGroupsForSector(
    sector: SchoolSectorKey,
    nextGroupsByLevel: Record<string, string[]>,
  ): void {
    const selectedLevelSet = new Set(selectedLevelsForCurrentSector.value);

    if (sector === "primairOnderwijs") {
      const target =
        params.formValues.value.levelSelection.selectedGroupsByLevel
          .primairOnderwijs;

      for (const levelKey of Object.keys(target) as PrimaryLevelKey[]) {
        if (!selectedLevelSet.has(levelKey)) {
          target[levelKey] = [];
          continue;
        }

        const validGroups = filterValidGroupsForLevel(
          levelKey,
          nextGroupsByLevel[levelKey] ?? [],
          availableLevelsForSector.value,
        ).filter(isPrimaryGroupKey);

        target[levelKey] = validGroups;
      }

      return;
    }

    if (sector === "voortgezetOnderbouw") {
      const target =
        params.formValues.value.levelSelection.selectedGroupsByLevel
          .voortgezetOnderbouw;

      for (const levelKey of Object.keys(target) as LowerSecondaryLevelKey[]) {
        if (!selectedLevelSet.has(levelKey)) {
          target[levelKey] = [];
          continue;
        }

        const validGroups = filterValidGroupsForLevel(
          levelKey,
          nextGroupsByLevel[levelKey] ?? [],
          availableLevelsForSector.value,
        ).filter(isLowerSecondaryGroupKey);

        target[levelKey] = validGroups;
      }

      return;
    }

    const target =
      params.formValues.value.levelSelection.selectedGroupsByLevel
        .voortgezetBovenbouw;

    for (const levelKey of Object.keys(target) as UpperSecondaryLevelKey[]) {
      if (!selectedLevelSet.has(levelKey)) {
        target[levelKey] = [];
        continue;
      }

      const validGroups = filterValidGroupsForLevel(
        levelKey,
        nextGroupsByLevel[levelKey] ?? [],
        availableLevelsForSector.value,
      ).filter(isUpperSecondaryGroupKey);

      target[levelKey] = validGroups;
    }
  }

  return {
    availableLevelsForSector,
    currentLevelSelectionRules,

    selectedLevelsForCurrentSector,
    selectedGroupsForCurrentSector,

    levelSelectionIssue,
    hasValidLevelSelection,

    groupSelectionIssues,
    hasValidGroupSelection,

    pruneGroupsForDeselectedLevels,
  };
}