import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import ts from "typescript";

const source = readFileSync(new URL("../../resources/js/admin/services/dashboardRosterApi.ts", import.meta.url), "utf8");
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
const api = {};
new Function("require", "exports", compiled.outputText)(() => ({ getApiData() { throw new Error("Unexpected unrelated API call"); } }), api);
const calls = [];
const validation = { planId: 12, revision: 4, valid: false, errors: [{ code: "OPEN_SUPERVISION", message: "OPEN" }], warnings: [] };
globalThis.fetch = async (url, options) => { calls.push({ url, options }); return new Response(JSON.stringify({ ok: true, data: { validation } }), { status: 200 }); };
assert.deepEqual(await api.validateDashboardRoster(12, 4), { validation });
assert.match(calls[0].url, /validate\.php\?planId=12&expectedRevision=4$/);
assert.equal(calls[0].options.credentials, "same-origin");
assert.equal(calls[0].options.cache, "no-store");
for (const action of ["finalize", "reopen"]) {
  await api.changeDashboardRosterLifecycle(action, 12, 4, `${action}-token`);
  const { url, options } = calls.at(-1);
  assert.ok(url.endsWith(`/${action}.php`));
  assert.equal(options.method, "POST");
  assert.equal(options.headers["X-CSRF-Token"], `${action}-token`);
  assert.deepEqual(JSON.parse(options.body), { planId: 12, expectedRevision: 4 });
}
for (const [status, code] of [[422, "ROSTER_INVALID"], [409, "STALE_REVISION"], [401, "UNAUTHENTICATED"], [403, "INVALID_CSRF"]]) {
  globalThis.fetch = async () => new Response(JSON.stringify({ ok: false, code, message: "server message", validation }), { status });
  await assert.rejects(api.changeDashboardRosterLifecycle("finalize", 12, 4, "token"), error => {
    assert.ok(error instanceof api.RosterLifecycleApiError);
    assert.equal(error.status, status); assert.equal(error.code, code); assert.deepEqual(error.validation, validation);
    assert.ok(error.message.length > 0); return true;
  });
}
console.log("OK: actual TypeScript lifecycle API preserves revisions, CSRF, validation and stale/session errors.");
