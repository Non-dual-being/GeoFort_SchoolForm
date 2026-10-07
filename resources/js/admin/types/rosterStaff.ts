export interface RosterStaffPreference {
  moduleKey: string;
  moduleLabel: string;
  rank: number;
  isLegacy: boolean;
}

export interface RosterStaffCostRate {
  validFrom: string;
  validTo: string | null;
  hourlyCostCents: number;
}

export interface RosterStaffMember {
  version: number;
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
  catalogRevision: number;
  modules: RosterStaffModule[];
}

export interface RosterStaffModule { moduleKey: string; moduleLabel: string }
export interface RosterStaffValues {
  name: string;
  isActive: boolean;
  employmentType: "paid" | "volunteer";
  canGuide: boolean;
  canCook: boolean;
  preferences: Array<{ moduleKey: string; rank: number }>;
}
export interface RosterStaffImpact { planId: number; visitDate: string; revision: number; reasons: string[] }
export interface RosterStaffImpactPreview { impact: RosterStaffImpact[]; impactToken: string; expiresInSeconds: number; catalogRevision: number; version: number }
export interface RosterStaffHistoryItem { id: number; actorId: number; action: string; previousVersion: number; version: number; before: RosterStaffValues | null; after: RosterStaffValues; impact: RosterStaffImpact[]; createdAt: string }
export interface RosterStaffDetail { member: RosterStaffMember; catalogRevision: number; history: RosterStaffHistoryItem[] }
export interface RosterStaffDeactivateRequest { staffId: number; expectedVersion: number; expectedCatalogRevision: number; impactToken: string }
