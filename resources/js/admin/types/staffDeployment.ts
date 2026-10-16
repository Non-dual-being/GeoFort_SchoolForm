export type DeploymentFilters = { from: string; to: string; year: string; range: string; status: string; roster: string; staff: string; employment: string };
export type DeploymentOption = { value: string; label: string };
export type DeploymentRow = { id: number; name: string; employment: string; minutes: number; statuses: Record<string, number>; schools: number; days: number; guideMinutes: number; cookMinutes: number; conceptMinutes: number; publishedMinutes: number; modules: { module: string; role: string; minutes: number; rejectedMinutes: number; lessons: number }[] };
export type DeploymentEntry = { staffId: number; name: string; date: string; planId: number; school: string; status: string; rosterStatus: string; start: string; end: string; moduleLabel: string; role: string; sessionId: number };
export interface DeploymentInsights {
  modules: { employment: string; module: string; minutes: number }[];
  minutes: number; spanMinutes: number; gapMinutes: number; breakMinutes: number; staff: number; days: number; schools: number;
  rankings: Record<string, { id: number; name: string; value: number }[]>;
  groups: { key: string; label: string; staff: number; minutes: number; spanMinutes: number; gapMinutes: number; breakMinutes: number; staffDays: number; averageMinutes: number; shortVisits: number }[];
  daily: { staffId: number; name: string; employment: string; date: string; minutes: number; spanMinutes: number; gapMinutes: number; breakMinutes: number; shortVisit: boolean }[];
}
export type DeploymentReport = { insights: DeploymentInsights; filters: DeploymentFilters; options: Record<string, DeploymentOption[]>; dateBounds: { min: string | null; max: string | null }; rows: DeploymentRow[]; entries: DeploymentEntry[] };
export const deploymentHours = (minutes: number) => {
  const rounded = Math.round(minutes);
  return `${Math.floor(rounded / 60)} u${rounded % 60 ? ` ${rounded % 60} min` : ''}`;
};
export const deploymentType = (value: string) => value === 'paid' ? 'Betaald' : value === 'volunteer' ? 'Vrijwilliger' : 'Onbekend';
