// Real Vue detail view and CSS, synthetic HTTP only: no application database or mail.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';

const root = fileURLToPath(new URL('../../', import.meta.url));
const output = path.join(process.env.ROSTER_ROUND_D_OUTPUT ?? fs.mkdtempSync(path.join(os.tmpdir(), 'geofort-round-d-')), 'browser-reset');
fs.mkdirSync(output, { recursive: true });
const executable = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
assert(fs.existsSync(executable), 'Set CHROME_PATH to installed Chromium.');
const rounds = [
  { type: 'welcome', label: 'Eigen aankomst en welkom', startTime: '10:05', endTime: '10:20' },
  { type: 'round', label: 'Eigen ronde 1', startTime: '10:20', endTime: '11:05' },
  { type: 'break', label: 'Eigen pauze', startTime: '11:05', endTime: '11:20' },
  { type: 'round', label: 'Eigen ronde 2', startTime: '11:20', endTime: '12:05' },
  { type: 'lunch', label: 'Eigen lunch', startTime: '12:05', endTime: '12:35' },
  { type: 'departure', label: 'Eigen vertrek', startTime: '12:35', endTime: '12:45' },
];
const groups = [1, 2].map(id => ({ id, label: 'Groep ' + id, position: id, studentCount: 14 }));
const modules = [
  { key: 'Earth-Watch', label: 'Earth Watch', color: '#c7e8e9', defaultLocation: 'Zaal A', maxParallel: 2, minimumGeoFortStaff: 1, schoolSupervisionAllowed: false },
  { key: 'Stop-de-Klimaat-Klok', label: 'Stop de Klimaat Klok', color: '#f4d8b0', defaultLocation: 'Zaal B', maxParallel: 2, minimumGeoFortStaff: 1, schoolSupervisionAllowed: true },
];
const staffCatalog = [1, 2].map(id => ({ id, name: 'Testdocent ' + id, isActive: true, employmentType: id === 1 ? 'paid' : 'volunteer', canGuide: true, canCook: false, preferences: modules.map(module => ({ moduleKey: module.key, moduleLabel: module.label, rank: 1 })) }));
const sessions = modules.map((module, index) => ({
  id: index + 1, moduleKey: module.key, moduleLabel: module.label, color: module.color, ...rounds[index === 0 ? 1 : 3],
  location: module.defaultLocation, groupIds: [1, 2], groupLabels: ['Groep 1', 'Groep 2'], minimumGeoFortStaff: 1,
  schoolSupervisionAllowed: module.schoolSupervisionAllowed, staffAssignments: [{ id: index + 1, name: 'Testdocent ' + (index + 1) }],
}));
function makePlan(id, status = 'concept') {
  return structuredClone({ id, bookingId: 100 + id, planningMode: 'custom', visitDate: '2028-10-10', status, revision: 11, needsReview: false, sourceCurrent: true,
    school: { name: 'Synthetische resettest ' + id, city: 'Testplaats', contactName: '', contactPhone: '', supervisorCount: 2 },
    education: { sector: 'primairOnderwijs', sectorLabel: 'Primair onderwijs', program: 'dag', programLabel: 'Dagprogramma', choiceModule: null, choiceModuleLabel: null, studentCount: 28 },
    groups, planning: { sessions, rounds, roundsSource: 'saved', modules, catalogRevision: 7, staffCatalog, selectedStaffIds: [1, 2],
      generation: { defaultRounds: [{ label: 'Default must not replace saved rounds', startTime: '09:00', endTime: '09:45' }], templateNote: '' },
      staffingSettings: { staffingMode: 'with_staff', preferGeoFortKe: false, cookStaffId: null, plannerModulePreferences: [{ staffId: 1, moduleKeys: ['Earth-Watch'] }] } } });
}
const plans = new Map([[1, makePlan(1)], [2, makePlan(2)], [3, makePlan(3, 'published')]]);
const requests = [], errors = [], consoleErrors = [];
let resetFailure = null;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const server = await createServer({ root, configFile: false, envDir: false, publicDir: false, cacheDir: path.join(output, 'vite'),
  optimizeDeps: { entries: [], include: ['vue', 'vue-router', 'lucide-vue-next'] }, plugins: [vue(), {
    name: 'synthetic-session-reset',
    configureServer(server) { server.middlewares.use((req, res, next) => {
      const url = new URL(req.url, 'http://127.0.0.1');
      if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/reset-fixture.js"></script></body></html>'); return; }
      if (!url.pathname.startsWith('/api/')) { next(); return; }
      const request = { method: req.method, path: url.pathname, token: req.headers['x-csrf-token'] ?? null, body: null }; requests.push(request);
      let body = ''; req.on('data', chunk => body += chunk); req.on('end', async () => {
        const send = (data, status = 200) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(data)); };
        try {
          request.body = body ? JSON.parse(body) : null;
          const current = plans.get(Number(url.searchParams.get('id') ?? request.body?.planId));
          if (url.pathname === '/api/admin/rosters/show.php') { send({ ok: true, data: { plan: current } }); return; }
          assert.equal(req.method, 'POST');
          await pause(450);
          if (url.pathname === '/api/admin/rosters/reset-sessions.php') {
            assert.equal(request.token, 'reset-only-token');
            assert.deepEqual(Object.keys(request.body).sort(), ['expectedRevision', 'planId']);
            if (resetFailure) { send({ ok: false, code: resetFailure.code, message: resetFailure.message }, resetFailure.status); return; }
            assert.equal(current.status, 'concept');
            assert.equal(request.body.expectedRevision, current.revision);
            const deletedSessionCount = current.planning.sessions.length;
            current.planning.sessions = [];
            if (deletedSessionCount) current.revision++;
            send({ ok: true, data: { planId: current.id, revision: current.revision, deletedSessionCount, alreadyEmpty: deletedSessionCount === 0 } }); return;
          }
          if (url.pathname === '/api/admin/rosters/session-save.php') {
            assert.equal(request.token, 'session-only-token'); assert.equal(request.body.expectedRevision, current.revision);
            const module = modules.find(module => module.key === request.body.moduleKey);
            current.planning.sessions.push({ ...sessions[0], id: 90, ...request.body, moduleLabel: module.label, color: module.color, groupLabels: request.body.groupIds.map(id => 'Groep ' + id), staffAssignments: [] });
            current.revision++; send({ ok: true, data: { revision: current.revision, sessionId: 90, warnings: [] } }); return;
          }
          if (url.pathname === '/api/admin/rosters/reopen.php') {
            assert.equal(request.token, 'reopen-only-token'); assert.equal(request.body.expectedRevision, current.revision);
            current.status = 'concept'; current.revision++; send({ ok: true, data: { plan: current } }); return;
          }
          throw new Error('Unexpected API: ' + url.pathname);
        } catch (error) { errors.push(String(error)); send({ ok: false, message: String(error) }, 500); }
      });
    }); },
    resolveId(id) { if (id === '/reset-fixture.js') return '\0reset-fixture'; },
    load(id) { if (id !== '\0reset-fixture') return; return `import {createApp,h} from 'vue';import {createRouter,createWebHashHistory,RouterView} from 'vue-router';
      import Detail from '/resources/js/admin/views/DashboardRosterDetailView.vue';import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts';import '/resources/css/admin.css';
      const Stub={render:()=>h('p','Vervolgpagina')};const router=createRouter({history:createWebHashHistory(),routes:[{path:'/rosters/:id',name:'roster-detail',component:Detail},{path:'/rosters',name:'rosters',component:Stub},{path:'/create',name:'roster-create',component:Stub}]});
      const app=createApp({render:()=>h(RouterView)});app.use(router);app.provide(adminBootstrapKey,{rosterPlanCsrfToken:'plan-only-token',rosterSessionCsrfToken:'session-only-token',rosterGenerationCsrfToken:'generation-only-token',rosterResetSessionsCsrfToken:'reset-only-token',rosterReopenCsrfToken:'reopen-only-token'});app.mount('#app');`; },
  }], server: { host: '127.0.0.1', port: 0, watch: null, hmr: false }, logLevel: 'error' });
let browser, socket, cdp;
async function until(callback, message) {
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) { if (await callback()) return; await pause(100); }
  throw new Error(message);
}
try {
  await server.listen(); const port = server.httpServer.address().port;
  const profile = fs.mkdtempSync(path.join(output, 'chrome-'));
  browser = spawn(executable, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
  await until(() => fs.existsSync(path.join(profile, 'DevToolsActivePort')), 'Chrome did not start');
  const debugPort = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`, { signal: AbortSignal.timeout(5000) })).json();
  socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { const timer = setTimeout(() => reject(new Error('CDP connect timeout')), 5000); socket.onopen = () => { clearTimeout(timer); resolve(); }; socket.onerror = reject; });
  const pending = new Map(); let sequence = 0;
  socket.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.id) { const p = pending.get(message.id); if (!p) return; clearTimeout(p.timer); pending.delete(message.id); message.error ? p.reject(message.error) : p.resolve(message.result); }
    else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
    else if (message.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(message.params.type)) consoleErrors.push(message.params.args.map(arg => arg.value ?? arg.description).join(' '));
  };
  cdp = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 10000); pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params })); });
  const evaluate = async expression => { const result = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
  const ready = expression => until(() => evaluate(expression), 'Browser condition failed: ' + expression);
  const button = label => `[...document.querySelectorAll('button')].find(el=>el.textContent.trim()===${JSON.stringify(label)})`;
  const mouseClick = async expression => {
    const point = await evaluate(`(()=>{const el=${expression};el.scrollIntoView({block:'center'});const r=el.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
    await cdp('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...point });
    await cdp('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...point });
  };
  const click = label => mouseClick(button(label));
  const key = async (key, code, virtualKey) => { await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: virtualKey }); await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: virtualKey }); };
  const screenshot = async name => { const shot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(path.join(output, name + '.png'), Buffer.from(shot.data, 'base64')); };
  const navigate = async id => { await cdp('Page.navigate', { url: `http://127.0.0.1:${port}/#/rosters/${id}` }); await ready(`document.querySelector('#roster-detail-title')?.textContent.includes(${JSON.stringify(plans.get(id).school.name)})`); };
  const resetRequests = () => requests.filter(request => request.path.endsWith('/reset-sessions.php'));
  const preserved = plan => { const copy = structuredClone(plan); delete copy.revision; delete copy.planning.sessions; return copy; };
  const manualRows = () => evaluate(`[...document.querySelector('.admin-roster-manual-workspace table').tBodies[0].rows].map(row=>({startTime:row.cells[0].querySelector('strong').textContent,endTime:row.cells[0].querySelector('span').textContent,label:row.querySelector('.admin-roster-grid-neutral')?.textContent.trim()??null}))`);
  const expectedRows = rounds.map(block => ({ startTime: block.startTime, endTime: block.endTime, label: block.type === 'round' ? null : block.label }));

  await cdp('Runtime.enable'); await cdp('Page.enable');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false });
  await navigate(1);
  for (const width of [1360, 390]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
    assert.equal(await evaluate("document.documentElement.scrollWidth<=innerWidth && getComputedStyle(document.querySelector('.admin-roster-reset-help')).display==='block'"), true, 'Reset explanation wraps without page overflow');
    await click('Lesindeling leegmaken'); await ready("document.querySelector('dialog')?.open");
    assert.equal(await evaluate("document.activeElement.hasAttribute('data-reset-cancel')"), true, 'Cancel receives initial focus');
    assert.equal(await evaluate("document.querySelector('#roster-reset-description').textContent.replace(/\\s+/g,' ').trim()"), 'Alle ingeplande lessen en bijbehorende groeps- en personeelstoewijzingen worden verwijderd. De groepen, dagindeling, geselecteerde medewerkers en algemene roosterinstellingen blijven bestaan. Deze actie kan niet ongedaan worden gemaakt.');
    assert.equal(await evaluate("(()=>{const d=document.querySelector('dialog');const r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight&&d.scrollWidth<=d.clientWidth;})()"), true, 'Dialog fits viewport without clipping');
    await screenshot('confirm-' + width);
    await key('Escape', 'Escape', 27); await ready("!document.querySelector('dialog')");
    assert.equal(await evaluate(`document.activeElement===${button('Lesindeling leegmaken')}`), true, 'Focus returns to invoking action');
  }
  assert.equal(resetRequests().length, 0, 'Escape never sends reset');
  await click('Lesindeling leegmaken'); await click('Annuleren'); await ready("!document.querySelector('dialog')");
  assert.equal(resetRequests().length, 0, 'Cancel never sends reset');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false });

  // Open a manual editor before reset: it must disappear with its old session state.
  await click('Beheren'); await click('Handmatige correcties');
  await mouseClick("document.querySelector('.admin-roster-edit-cell__full')"); await ready("document.querySelector('.admin-roster-cell-editor')!==null");
  const before = preserved(plans.get(1));
  await click('Lesindeling leegmaken'); await click('Lesindeling verwijderen');
  await ready("[...document.querySelectorAll('dialog button')].every(button=>button.disabled)");
  await key('Escape', 'Escape', 27); assert.equal(await evaluate("document.querySelector('dialog').open"), true, 'Busy Escape does not dismiss request');
  await evaluate(`${button('Lesindeling verwijderen')}.click()`);
  await ready("!document.querySelector('dialog') && document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 12'");
  assert.equal(resetRequests().length, 1, 'One POST despite second click'); assert.deepEqual(resetRequests()[0].body, { planId: 1, expectedRevision: 11 });
  assert.deepEqual(preserved(plans.get(1)), before); assert.equal(plans.get(1).planning.sessions.length, 0);
  assert.equal(await evaluate("document.querySelector('.admin-roster-cell-editor')"), null, 'Old manual editor unmounted');
  await ready("document.body.textContent.includes('De lesindeling is leeggemaakt.')");
  await click('Beheren'); await click('Handmatige correcties');
  assert.deepEqual(await manualRows(), expectedRows, 'All saved rounds and neutral blocks survive empty grid');
  assert.equal(await evaluate("document.querySelectorAll('.admin-roster-edit-cell__add').length"), 4);
  await evaluate("document.querySelector('.admin-roster-manual-workspace').scrollIntoView({block:'start'})"); await screenshot('empty-saved-timeline');
  await mouseClick("document.querySelector('.admin-roster-edit-cell__add')");
  await click('Opslaan');
  await ready("document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 13' && !document.querySelector('.admin-roster-cell-editor')");
  assert.deepEqual(await manualRows(), expectedRows, 'Unused rounds do not disappear after first manual lesson');
  assert.equal(await evaluate("document.querySelectorAll('.admin-roster-edit-cell__add').length"), 3);
  const save = requests.find(request => request.path.endsWith('/session-save.php'));
  assert.equal(save.body.startTime, '10:20'); assert.equal(save.body.endTime, '11:05');
  await click('Lesindeling leegmaken'); await click('Lesindeling verwijderen'); await ready("!document.querySelector('dialog') && document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 14'");
  await click('Lesindeling leegmaken'); await click('Lesindeling verwijderen'); await ready("!document.querySelector('dialog') && document.body.textContent.includes('De lesindeling was al leeg. Er is niets gewijzigd.')");
  assert.equal(plans.get(1).revision, 14); assert.equal(await evaluate("document.querySelector('.admin-roster-detail__revision').textContent"), 'Revisie 14');

  await navigate(2); const failureBefore = structuredClone(plans.get(2));
  resetFailure = { status: 500, code: 'RESET_FAILED', message: 'Synthetische resetfout: er is niets gewijzigd.' };
  await click('Lesindeling leegmaken'); await click('Lesindeling verwijderen'); await ready("!document.querySelector('dialog') && document.body.textContent.includes('Synthetische resetfout')");
  assert.deepEqual(plans.get(2), failureBefore);
  await pause(5200); assert.equal(await evaluate("document.body.textContent.includes('Synthetische resetfout')"), true, 'Server errors remain visible');
  resetFailure = { status: 409, code: 'ROSTER_REVISION_CONFLICT', message: 'Dit rooster is ondertussen gewijzigd. Laad de nieuwste versie.' };
  await click('Lesindeling leegmaken'); plans.get(2).revision++;
  const postsBeforeConflict = resetRequests().length;
  await click('Lesindeling verwijderen'); await ready("!document.querySelector('dialog') && document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 12'");
  assert.equal(await evaluate("document.body.textContent.includes('Dit rooster is ondertussen gewijzigd.')"), true);
  assert.equal(resetRequests().length, postsBeforeConflict + 1, 'Conflict refresh does not auto-retry reset');
  assert.equal(plans.get(2).planning.sessions.length, 2);
  resetFailure = null;
  await navigate(3); assert.equal(await evaluate(`Boolean(${button('Lesindeling leegmaken')})`), false, 'Published plan has no reset action');
  assert.match(await evaluate('document.body.textContent'), /[Hh]eropen.*lesindeling/s);
  await evaluate('window.confirm=()=>true'); await click('Heropenen voor wijzigingen'); await ready("document.querySelector('[aria-label=Roosterstatus]').textContent==='Concept'");
  await click('Lesindeling leegmaken'); await click('Lesindeling verwijderen'); await ready("!document.querySelector('dialog') && document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 13'");
  assert.equal(plans.get(3).planning.sessions.length, 0);
  assert.deepEqual(errors, []); assert.deepEqual(consoleErrors, []);
  assert(requests.every(request => request.path.startsWith('/api/admin/rosters/')));
  fs.writeFileSync(path.join(output, 'checks.json'), JSON.stringify({ result: 'passed', requests, widths: [1360, 390], rounds: expectedRows, errors, consoleErrors }, null, 2));
  console.log('PASS reset dialog/cancel/focus/loading, own CSRF/revision, error/conflict/no-op, saved rounds after manual save, published/reopen; synthetic HTTP only. Artifacts: ' + output);
} catch (error) {
  if (cdp) { try { const state = await cdp('Runtime.evaluate', { expression: 'document.documentElement.outerHTML', returnByValue: true }); fs.writeFileSync(path.join(output, 'failure.json'), JSON.stringify({ requests, errors, consoleErrors, html: state.result.value }, null, 2)); } catch {} }
  console.error('Browser artifacts: ' + output); throw error;
} finally { if (cdp) { try { await cdp('Browser.close'); } catch {} } socket?.close(); browser?.kill(); await server.close(); }
