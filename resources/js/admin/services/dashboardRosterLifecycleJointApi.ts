import { getApiData } from '../../services/http/apiClient';
export interface JointControl { signature: string; canFinalize: boolean; errors: {code:string;message:string}[]; warnings: {code:string;message:string}[]; applied: boolean; status?: string }
export function jointRosterLifecycle(plans: readonly {id:number;revision:number}[], token:string, mode:'preview'|'finalize'|'reopen', signature?:string, acceptedWarnings=false):Promise<JointControl> {
  return getApiData<JointControl>('/api/admin/rosters/joint-lifecycle.php', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({plans:plans.map(({id,revision})=>({id,revision})),mode,signature,acceptedWarnings})});
}
