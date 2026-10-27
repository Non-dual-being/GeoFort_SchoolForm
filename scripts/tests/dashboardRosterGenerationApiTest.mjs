import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
function load(path, dependencies = {}) {
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(key => { assert.ok(key in dependencies); return dependencies[key]; }, exports);
  return exports;
}
const client = load('../../resources/js/services/http/apiClient.ts');
const api = load('../../resources/js/admin/services/dashboardRosterGenerationApi.ts', { '../../services/http/apiClient': client });
const options = { staffingMode:'with_staff', staffIds:[3], cookStaffId:8, preferGeoFortKe:true, plannerModulePreferences:[], useExistingLessons:true, optimizeExistingLessons:true };
const proposal = { planId:18, canApply:true, canFinalize:false, hardIssues:[{code:'GROUP_GUIDES_REQUIRED',message:'Eén begeleider ontbreekt'}] };
const originalFetch = globalThis.fetch;
try {
  globalThis.fetch = async (url, init) => {
    assert.equal(url, '/api/admin/rosters/generation-preview.php');
    assert.equal(init.method, 'POST');
    assert.equal(new Headers(init.headers).get('X-CSRF-Token'), 'preview-token');
    assert.deepEqual(JSON.parse(init.body), { planId:18, rounds:[], ...options });
    return new Response(JSON.stringify({ok:true,data:proposal}));
  };
  assert.deepEqual(await api.previewGeneratedRoster(18,[],options,'preview-token'), proposal);
  for (const [status, body] of [[200,''],[200,'{broken'],[500,JSON.stringify({ok:false,type:'server',code:500})],[502,'<html>Bad gateway</html>']]) {
    globalThis.fetch = async () => new Response(body, {status});
    await assert.rejects(api.previewGeneratedRoster(18,[],options,'preview-token'), error => {
      assert.ok(error instanceof client.ApiError);
      assert.equal(error.status,status);
      assert.doesNotMatch(error.message,/Unexpected|SyntaxError/);
      return true;
    });
  }
} finally { globalThis.fetch = originalFetch; }
console.log('PASS actual generator client: incomplete Unicode proposal, protected request and empty/server-error responses');
