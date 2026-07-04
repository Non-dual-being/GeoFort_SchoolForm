import type { ProgramKey } from "./BookingProgramConfigTypes";

export type ProgrammaBeleid = {
  label: string;
  maxLeerlingenPerDag: number;
};

export type BoekingBeleidApiResponse = {
  programma: Record<ProgramKey, ProgrammaBeleid>;
  limieten: {
    maxScholenPerDag: number;
    maxStudentenTotaal: number;
  };
  boekingregels: {
    agendabereik: number;
  };
  statussen: {
    actief: string[];
  };
};