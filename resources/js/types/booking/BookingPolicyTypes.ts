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
    leerlingenPerGratisBegeleider: number;
    leerlingenPerVerplichteBegeleider: number;
    maxBegeleidersPerBoeking: number;
  };
  boekingregels: {
    agendabereik: number;
  };
  statussen: {
    actief: string[];
  };
};
