export type ProgrammaBeleid = {
  label: string;
  maxLeerlingenPerDag: number;
};

export type BoekingBeleidApiResponse = {
  programma: {
    regulier: ProgrammaBeleid;
    ochtend: ProgrammaBeleid;
  };
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