import { getApiData } from '../../services/http/apiClient';
type Person = { staffId: number; name: string; gapMinutes: number; workMinutes: number; cookMinutes: number };
type Metrics = { unfilledAssignments: number; keSchoolAssignments: number; schoolAssignments: number; workSpreadMinutes: number; morningGroupChanges: number; paidGapMinutes: number; volunteerGapMinutes: number; people: number; staff: Person[] };
export interface JointOptimization {
  signature: string; improved: boolean; applied: boolean; searchLimited: boolean;
  before: Metrics; after: Metrics;
  finalizationIssues: { code: string; message: string; school: number; startTime: string; endTime: string; groupId: number }[];
  changes: { school: number; sessionId: number; startTime: string; endTime: string; module: string; role: string; from: string; to: string }[];
}
export function optimizeJointRoster(plans: readonly { id: number; revision: number }[], token: string, signature?: string): Promise<JointOptimization> {
  return getApiData<JointOptimization>('/api/admin/rosters/joint-optimize.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify({ plans: plans.map(({ id, revision }) => ({ id, revision })), mode: signature ? 'apply' : 'preview', signature }),
  });
}
