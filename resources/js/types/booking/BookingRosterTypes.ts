import type { EducationModuleKey } from "./BookingProgramConfigTypes";

export type BookingRosterModule = {
  key: EducationModuleKey | string;
  label: string;
};

export type BookingRosterResult = {
  available: boolean;
  groupCount: number | null;
  standardModules: BookingRosterModule[];
  choiceModule: BookingRosterModule | null;
  imageUrl: string | null;
  pdfUrl: string | null;
  message: string | null;
};

export type BookingRosterEducationSelectionPayload = {
  sector: string;
  selectedLevels: string[];
  selectedGroupsByLevel: Record<string, string[]>;
};

export type BookingRosterRequest = {
  bezoekdatum: string;
  onderwijsSector: string;
  programma: string;
  educationSelection: BookingRosterEducationSelectionPayload;
  keuzemodule: string;
  aantalLeerlingen: string;
  aantalBegeleiders: string;
};
