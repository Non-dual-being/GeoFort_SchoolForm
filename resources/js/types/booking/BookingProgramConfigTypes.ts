export type SchoolSectorKey =
  | "primairOnderwijs"
  | "voortgezetOnderbouw"
  | "voortgezetBovenbouw";

export type ProgramKey = "ochtend" | "dag";

export type RoosterType = "primair" | "onderbouw" | "bovenbouw";

export type PriceType = "basis" | "voortgezet";

export type Category = "basis" | "voortgezet";

export type CategoryLabel = "PO" | "VO";

export type Weekday = 1 | 2 | 3 | 4 | 5;

export type TimeString = `${number}:${number}`;

export type SchoolTypeConfig = {
  label: string;
  roosterType: RoosterType;
  priceType: PriceType;
  category: Category;
};

export type SchoolSectorType = SchoolTypeConfig & {
  value: SchoolSectorKey;
}

export type ProgramConfig = {
  label: string;
  beginTijd: TimeString;
  eindTijd: TimeString;
  duurLesmodule: string;
  allowedSchoolTypes: SchoolSectorKey[];
  allowedWeekdays: Weekday[];
  description: string[];
};

export type ModuleName =
  | "Zandtafel"
  | "Rising-Risk"
  | "Dynamische-Globe"
  | "Dynamische-Globe-Bios"
  | "Expedition-Earth"
  | "Klimaat-Experience"
  | "Klimparcours"
  | "Voedsel-Innovatie"
  | "Minecraft-Klimaatspeurtocht"
  | "Earth-Watch"
  | "Stop-de-Klimaat-Klok"
  | "Minecraft-Programmeren"
  | "Minecraft-Windenergiespeurtocht"
  | "Crisismanagement";

export type KeuzeModule =
  | "Klimparcours"
  | "Minecraft-Klimaatspeurtocht"
  | "Minecraft-Windenergiespeurtocht"
  | "Minecraft-Programmeren"
  | "Earth-Watch"
  | "Stop-de-Klimaat-Klok"
  | "Crisismanagement";

export type ModuleSelection = {
  standaard: ModuleName[];
  keuze: KeuzeModule[];
};

export type ModulesConfig = {
  primairOnderwijs: {
    ochtend: ModuleSelection;
    dag: ModuleSelection;
  };
  voortgezetOnderbouw: {
    dag: ModuleSelection;
  };
  voortgezetBovenbouw: {
    dag: ModuleSelection;
  };
};

export type VisitPricesConfig = {
  ochtend: {
    basis: number;
  };
  dag: {
    basis: number;
    voortgezet: number;
  };
};

export type SnackKey =
  | "remise_break"
  | "kazerne_break"
  | "fortgracht_break"
  | "glas_limonade"
  | "waterijsje";

export type LunchKey = "remise_lunch" | "eigen_picknick";

export type PricesConfig = {
  bezoek: VisitPricesConfig;
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

export type BookingProgramConfigData = {
  schoolTypes: SchoolSectorType[];
  programs: Record<ProgramKey, ProgramConfig>;
  modules: ModulesConfig;
  prices: PricesConfig;
  studentLimits: StudentLimitsConfig;
  practicalInfo: PracticalInfoConfig;
};