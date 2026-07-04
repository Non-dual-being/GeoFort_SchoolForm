export type SchoolSectorKey =
  | "primairOnderwijs"
  | "voortgezetOnderbouw"
  | "voortgezetBovenbouw";

export type ProgramKey = "ochtend" | "dag";

export type RoosterType = "primair" | "onderbouw" | "bovenbouw";

export type PriceType = "basis" | "voortgezet";

export type Category = "basis" | "voortgezet";

export type Weekday = 1 | 2 | 3 | 4 | 5;

export type TimeString = `${number}:${number}`;

export type PrimaryLevelKey = "regulier" | "speciaal";


export type LowerSecondaryLevelKey =
  | "vmboBasisKader"
  | "vmboGemengdTheoretisch"
  | "havo"
  | "vwo"
  | "praktijkOnderwijs";

export type UpperSecondaryLevelKey =
  | "vmbo"
  | "havo"
  | "vwo"
  | "praktijkOnderwijs";

export type PrimaryGroupKey =
  | "groep5"
  | "groep6"
  | "groep7"
  | "groep8";

export type LowerSecondaryGroupKey =
  | "vmbo1"
  | "vmbo2"
  | "vmbo3"
  | "havo1"
  | "havo2"
  | "havo3"
  | "atheneum1"
  | "atheneum2"
  | "atheneum3"
  | "gymnasium1"
  | "gymnasium2"
  | "gymnasium3"
  | "praktijk1"
  | "praktijk2"
  | "praktijk3";

export type UpperSecondaryGroupKey =
  | "vmbo4"
  | "havo4"
  | "havo5"
  | "atheneum4"
  | "atheneum5"
  | "atheneum6"
  | "gymnasium4"
  | "gymnasium5"
  | "gymnasium6"
  | "praktijk4"
  | "praktijk5";

export type AnyLevelKey =
  | PrimaryLevelKey
  | LowerSecondaryLevelKey
  | UpperSecondaryLevelKey;

export type AnyGroupKey =
  | PrimaryGroupKey
  | LowerSecondaryGroupKey
  | UpperSecondaryGroupKey;

export type SchoolTypeConfig = {
  label: string;
  roosterType: RoosterType;
  priceType: PriceType;
  category: Category;
};

export type SchoolSectorType = SchoolTypeConfig & {
  value: SchoolSectorKey;
};

export type ProgramConfig = {
  label: string;
  beginTijd: TimeString;
  eindTijd: TimeString;
  duurLesmodule: string;
  allowedSchoolTypes: SchoolSectorKey[];
  allowedWeekdays: Weekday[];
  description: string[];
};

export type LevelConfig<GroupKey extends string> = {
  label: string;
  groups: Record<GroupKey, string>;
};

export type SchoolLevelsConfig = {
  primairOnderwijs: Record<PrimaryLevelKey, LevelConfig<PrimaryGroupKey>>;
  voortgezetOnderbouw: Record<
    LowerSecondaryLevelKey,
    LevelConfig<LowerSecondaryGroupKey>
  >;
  voortgezetBovenbouw: Record<
    UpperSecondaryLevelKey,
    LevelConfig<UpperSecondaryGroupKey>
  >;
};

export type SchoolLevelSelectionRule = {
  minLevels: number;
  maxLevels: number;
  minGroupsPerLevel: number;
  maxGroupsPerLevel: number;
};

export type SchoolLevelSelectionRules = {
  [S in SchoolSectorKey]: SchoolLevelSelectionRule;
};

export type LevelKeyBySector = {
  primairOnderwijs: PrimaryLevelKey;
  voortgezetOnderbouw: LowerSecondaryLevelKey;
  voortgezetBovenbouw: UpperSecondaryLevelKey;
};

export type GroupKeyBySector = {
  primairOnderwijs: PrimaryGroupKey;
  voortgezetOnderbouw: LowerSecondaryGroupKey;
  voortgezetBovenbouw: UpperSecondaryGroupKey;
};

export type LevelKeyForSector<S extends SchoolSectorKey> = LevelKeyBySector[S];

export type GroupKeyForSector<S extends SchoolSectorKey> = GroupKeyBySector[S];

export type LevelOptionForSector<S extends SchoolSectorKey> = {
  key: LevelKeyForSector<S>;
  label: string;
  groups: {
    key: GroupKeyForSector<S>;
    label: string;
  }[];
};

export type SelectedLevelsBySector = {
  primairOnderwijs: PrimaryLevelKey[];
  voortgezetOnderbouw: LowerSecondaryLevelKey[];
  voortgezetBovenbouw: UpperSecondaryLevelKey[];
};

export type SelectedGroupsByLevel = {
  primairOnderwijs: Record<PrimaryLevelKey, PrimaryGroupKey[]>;
  voortgezetOnderbouw: Record<LowerSecondaryLevelKey, LowerSecondaryGroupKey[]>;
  voortgezetBovenbouw: Record<UpperSecondaryLevelKey, UpperSecondaryGroupKey[]>;
};

export type SchoolLevelSelectionState = {
  selectedLevels: SelectedLevelsBySector;
  selectedGroupsByLevel: SelectedGroupsByLevel;
};

export type BookingProgramsType = Record<ProgramKey, ProgramConfig> 

export type LevelOptionForAnySector = {
  key: AnyLevelKey;
  label: string;
  groups: {
    key: AnyGroupKey;
    label: string;
  }[];
};

/**lunch, snacks, practical, price and studentlimitinfo */

export type SnackKey =
  | "remise_break"
  | "kazerne_break"
  | "fortgracht_break"
  | "glas_limonade"
  | "waterijsje";

export type LunchKey =
  | "remise_lunch"
  | "eigen_picknick";



export type PricesConfig = {
  bezoek: {
    ochtend: {
      basis: number;
    };
    dag: {
      basis: number;
      voortgezet: number;
    };
  };
  snacks: Record<SnackKey, number>;
  lunch: Record<LunchKey, number>;
};

export type StudentLimitsConfig = {
  min: {
    ochtend: {
      basis: number;
    };
    dag: {
      basis: number;
      voortgezet: number;
    };
  };
  max: {
    ochtend: number;
    dag: number;
  };
};

export type PracticalInfoConfig = {
  included: string[];
  specialNotes: string[];
  vatText: string;
};

/**types for chosen module */
export type EducationModuleKey = string;

export type ModuleGroupType = "standaard" | "keuze";

export type ModuleGroupConfig = Record<ModuleGroupType, EducationModuleKey[]>;

export type EducationModulesConfig = {
  [S in SchoolSectorKey]: Partial<Record<ProgramKey, ModuleGroupConfig>>;
};

export type ModuleGroupFilterRule = "*" | AnyGroupKey[];

export type ModuleFilterForSector<S extends SchoolSectorKey> = Partial<
  Record<LevelKeyForSector<S>, ModuleGroupFilterRule>
>;

export type EducationModuleFilter = {
  primairOnderwijs?: ModuleFilterForSector<"primairOnderwijs">;
  voortgezetOnderbouw?: ModuleFilterForSector<"voortgezetOnderbouw">;
  voortgezetBovenbouw?: ModuleFilterForSector<"voortgezetBovenbouw">;
};

export type EducationModuleFiltersConfig = Record<
  EducationModuleKey,
  EducationModuleFilter
>;

export type ProgramOption = ProgramConfig & {
  key: ProgramKey;
};

export type EducationModuleOption = {
  key: EducationModuleKey;
  label: string;
};

export type EducationModuleGroupOptions = {
  standaard: EducationModuleOption[];
  keuze: EducationModuleOption[];
};

export type BookingProgramConfigData = {
  schoolTypes: SchoolSectorType[];
  schoolTypesByKey: Record<SchoolSectorKey, SchoolTypeConfig>;

  programs: BookingProgramsType;

  schoolLevels: SchoolLevelsConfig;
  schoolLevelSelectionRules: SchoolLevelSelectionRules;

  modules: EducationModulesConfig;
  moduleLabels: Record<EducationModuleKey, string>;
  moduleFilters: EducationModuleFiltersConfig;

  prices: PricesConfig;
  studentLimits: StudentLimitsConfig;
  practicalInfo: PracticalInfoConfig;

};