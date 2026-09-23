export interface RosterStaffPreference {
  moduleKey: string;
  moduleLabel: string;
  rank: number;
}

export interface RosterStaffCostRate {
  validFrom: string;
  validTo: string | null;
  hourlyCostCents: number;
}

export interface RosterStaffMember {
  id: number;
  name: string;
  isActive: boolean;
  employmentType: "paid" | "volunteer" | string;
  canGuide: boolean;
  canCook: boolean;
  notes: string | null;
  preferences: RosterStaffPreference[];
  costRates: RosterStaffCostRate[];
}

export interface RosterStaffCatalogResponse {
  items: RosterStaffMember[];
}
