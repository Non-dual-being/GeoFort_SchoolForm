// Actual Vue status workflow with synthetic HTTP and a local PDF fixture. No application DB or mail transport.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';

const root = fileURLToPath(new URL('../../', import.meta.url));
const output = path.resolve(process.env.ROSTER_ROUND_B_BROWSER ?? fs.mkdtempSync(path.join(os.tmpdir(), 'geofort-school-browser-')));
fs.mkdirSync(output, { recursive: true });
const downloads = fs.mkdtempSync(path.join(output, 'download-'));
const pdfFixture = fs.readFileSync(path.join(root, 'public/assets/booking/documents/Routekaart_Bussen_GeoFort_Onderwijs.pdf'));
const requests = [], errors = [], network = [];
let available = false, optionStatus = 200, prepareStatus = 200, downloadStatus = 200, statusCode = 'SUCCESS';
const preparedBookings = new Set();
const meta = { snapshotId: 101, revision: 12, finalizedAt: '2026-10-02T10:15:00Z', visitDate: '2028-10-10' };
const server = await createServer({ root, configFile: false, envDir: false, publicDir: false, cacheDir: path.join(output, 'vite'),
  optimizeDeps: { entries: [], include: ['vue', 'lucide-vue-next'] }, plugins: [vue(), {
    name: 'school-roster-fixture',
    configureServer(server) { server.middlewares.use((req, res, next) => {
      const url = new URL(req.url, 'http://127.0.0.1');
      if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/fixture-entry.js"></script></body></html>'); return; }
      if (!url.pathname.startsWith('/api/')) { next(); return; }
      let body = '';
      req.on('data', chunk => { body += chunk; });
      req.on('end', async () => {
        const request = { method: req.method, path: url.pathname, query: Object.fromEntries(url.searchParams), body: body ? JSON.parse(body) : null, token: req.headers['x-csrf-token'] };
        requests.push(request);
        const json = (value, status = 200) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(value)); };
        if (url.pathname.endsWith('/roster-attachment.php')) {
          const value = available ? meta : null;
          await new Promise(resolve => setTimeout(resolve, 120));
          json({ ok: optionStatus === 200, data: { plannerFinal: value } }, optionStatus); return;
        }
        if (url.pathname.endsWith('/prepare-roster-document.php')) {
          if (req.method !== 'POST' || request.token !== 'school-document-csrf') { errors.push('Prepare method/CSRF mismatch'); json({ ok: false }, 403); return; }
          const responseStatus = prepareStatus;
          await new Promise(resolve => setTimeout(resolve, 500));
          if (responseStatus !== 200) { json({ ok: false, message: responseStatus === 409 ? 'Test: dit rooster is heropend.' : 'Test: voorbereiding mislukt.' }, responseStatus); return; }
          preparedBookings.add(request.body.bookingId + ':' + request.body.snapshotId);
          json({ ok: true, data: { document: { snapshotId: meta.snapshotId, filename: 'Schoolrooster.pdf', mimeType: 'application/pdf', sha256: createHash('sha256').update(pdfFixture).digest('hex'), byteLength: pdfFixture.length } } }); return;
        }
        if (url.pathname.endsWith('/school-pdf.php')) {
          if (!preparedBookings.has(request.query.bookingId + ':' + request.query.snapshotId)) { errors.push('Download before prepare'); json({ ok: false }, 409); return; }
          if (downloadStatus !== 200) { json({ ok: false, message: 'Test: dit rooster is heropend.' }, downloadStatus); return; }
          res.setHeader('Content-Type', 'application/pdf'); res.setHeader('Content-Disposition', 'attachment; filename="Schoolrooster.pdf"');
          res.end(pdfFixture); return;
        }
        if (url.pathname.endsWith('/update-status.php')) {
          json({ ok: statusCode === 'SUCCESS', code: statusCode, bookingId: request.body.bookingId, previousStatus: 'In optie', currentStatus: statusCode === 'SUCCESS' ? request.body.targetStatus : 'In optie', mailMode: request.body.mailMode, mailSent: false, validationIssues: [], capacity: null }, statusCode === 'SUCCESS' ? 200 : 409); return;
        }
        errors.push('Unexpected endpoint: ' + url.pathname); json({ ok: false }, 404);
      });
    }); },
    resolveId(id) { if (id === '/fixture-entry.js') return '\0school-fixture'; },
    load(id) { if (id !== '\0school-fixture') return; return `import {createApp,h,ref} from 'vue';
      import Status from '/resources/js/admin/components/bookings/BookingStatusPanel.vue';
      import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts';import '/resources/css/admin.css';
      const id=ref(1), result=ref('');window.setBooking=value=>{id.value=value;result.value='';};window.results=[];
      const app=createApp({render:()=>h('div',[h(Status,{bookingId:id.value,currentStatus:'In optie',onCompleted:(...args)=>{window.results.push(args);result.value=args[1];}}),h('p',{id:'result',role:'status'},result.value)])});
      app.provide(adminBootstrapKey,{bookingStatusCsrfToken:'school-status-csrf',rosterDocumentCsrfToken:'school-document-csrf'});app.mount('#app');`; }
  }], server: { host: '127.0.0.1', port: 0, watch: null, hmr: false }, logLevel: 'error' });
let browser, socket, inspect;
const downloadEvents = [];
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, message) { const end = Date.now() + 20000; while (Date.now() < end) { if (await fn()) return; await wait(80); } throw new Error(message); }
try {
  await server.listen();
  const profile = fs.mkdtempSync(path.join(output, 'chrome-'));
  browser = spawn(process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
  await until(() => fs.existsSync(path.join(profile, 'DevToolsActivePort')), 'Chrome did not start');
  const debugPort = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  const pending = new Map(); let id = 0;
  socket.onmessage = event => {
    const data = JSON.parse(event.data);
    if (data.id) { const task = pending.get(data.id); if (!task) return; clearTimeout(task.timer); pending.delete(data.id); data.error ? task.reject(data.error) : task.resolve(data.result); }
    else if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description ?? data.params.exceptionDetails.text);
    else if (data.method?.startsWith('Browser.download')) downloadEvents.push(data);
    else if (data.method === 'Network.loadingFailed') network.push(data.params);
    else if (data.method === 'Network.responseReceived' && (data.params.type === 'Document' || data.params.response.status >= 400)) network.push({ url: data.params.response.url, status: data.params.response.status, type: data.params.type });
  };
  const cdp = (method, params = {}) => new Promise((resolve, reject) => { const key = ++id; const timer = setTimeout(() => reject(new Error('CDP timeout: ' + method)), 12000); pending.set(key, { resolve, reject, timer }); socket.send(JSON.stringify({ id: key, method, params })); });
  const evaluate = async expression => { const r = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails)); return r.result.value; };
  inspect = evaluate;
  const ready = expression => until(() => evaluate(expression), 'Browser condition: ' + expression);
  const button = label => `[...document.querySelectorAll('button')].find(el=>el.textContent.trim()===${JSON.stringify(label)})`;
  const click = async label => {
    const point = await evaluate(`(()=>{const el=${button(label)};el.scrollIntoView({block:'center'});const r=el.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
    await cdp('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...point });
    await cdp('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...point });
  };
  const target = async label => { await evaluate("document.querySelector('#admin-select-status-target').click()"); await ready("!!document.querySelector('[role=option]')"); await evaluate(`[...document.querySelectorAll('[role=option]')].find(el=>el.textContent.trim()===${JSON.stringify(label)}).click()`); };
  const open = async () => { await click('Status wijzigen en e-mail sturen'); await ready("document.querySelector('dialog').open && !document.querySelector('dialog').textContent.includes('Beschikbare roosters controleren')"); };
  const shot = async name => { const r = await cdp('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(output, name + '.png'), Buffer.from(r.data, 'base64')); };
  await cdp('Runtime.enable'); await cdp('Page.enable'); await cdp('Network.enable');
  await cdp('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: downloads, eventsEnabled: true });
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  const navigation = await cdp('Page.navigate', { url: `http://127.0.0.1:${server.httpServer.address().port}/` });
  if (navigation.errorText) throw new Error('Fixture navigation: ' + navigation.errorText);
  await ready("!!document.querySelector('#admin-select-status-target')");
  await target('Definitief'); await open();
  assert.equal(await evaluate("document.querySelectorAll('[name=roster-attachment]').length"), 0);
  await click('Bevestigen'); await ready('window.results.length===1');
  assert.equal(requests.at(-1).body.rosterAttachmentMode, 'example'); assert.equal(requests.at(-1).token, 'school-status-csrf');
  available = true; await evaluate('window.setBooking(2)'); await target('Definitief'); await open();
  assert.equal(await evaluate("document.querySelector('[value=example]').checked"), true);
  assert.match(await evaluate("document.querySelector('dialog').textContent"), /Revisie 12/);
  await shot('example-default-desktop');
  assert.equal(requests.filter(request => request.path.endsWith('/prepare-roster-document.php')).length, 0);
  const statusRequestsBeforePrepare = requests.filter(request => request.path.endsWith('/update-status.php')).length;
  prepareStatus = 500;
  await evaluate("document.querySelector('[value=planner_final]').click()");
  await ready("document.querySelector('dialog').textContent.includes('Schoolrooster voorbereiden')");
  assert.equal(await evaluate(`${button('Bevestigen')}.disabled`), true);
  assert.equal(await evaluate(`!!${button('Schoolrooster downloaden (PDF)')}`), false);
  await click('Bevestigen');
  await ready("document.querySelector('dialog [role=alert]')?.textContent.includes('voorbereiding mislukt')");
  assert.equal(await evaluate("document.querySelector('[value=planner_final]').checked"), true);
  assert.equal(await evaluate(`${button('Bevestigen')}.disabled`), true);
  assert.equal(requests.filter(request => request.path.endsWith('/update-status.php')).length, statusRequestsBeforePrepare);
  assert.equal(requests.filter(request => request.path.endsWith('/school-pdf.php')).length, 0);
  const prepareRequest = requests.at(-1);
  assert.equal(prepareRequest.method, 'POST'); assert.equal(prepareRequest.token, 'school-document-csrf');
  assert.deepEqual(prepareRequest.body, { bookingId: 2, snapshotId: 101 });
  await shot('prepare-error-desktop');
  prepareStatus = 409; await click('Opnieuw voorbereiden');
  await ready("document.querySelector('dialog [role=alert]')?.textContent.includes('heropend')");
  assert.equal(await evaluate("document.querySelector('[value=planner_final]').checked"), true);
  assert.equal(await evaluate(`${button('Bevestigen')}.disabled`), true);
  // Returning to example requires an explicit user choice.
  await evaluate("document.querySelector('[value=example]').click()"); await ready(`${button('Bevestigen')}.disabled===false`);
  prepareStatus = 200;
  await evaluate("document.querySelector('[value=planner_final]').click()"); await ready("!![...document.querySelectorAll('button')].find(el=>el.textContent.includes('Schoolrooster downloaden'))");
  downloadStatus = 409; await click('Schoolrooster downloaden (PDF)'); await ready("document.querySelector('dialog [role=alert]')?.textContent.includes('heropend')");
  await wait(200); assert.equal(await evaluate("document.querySelector('[value=planner_final]').checked"), true);
  assert.equal(await evaluate(`${button('Bevestigen')}.disabled`), true);
  downloadStatus = 200; await click('Opnieuw voorbereiden'); await ready(`!!${button('Schoolrooster downloaden (PDF)')}`);
  await click('Schoolrooster downloaden (PDF)');
  await until(() => downloadEvents.some(event => event.params.state === 'completed'), 'PDF download missing');
  assert.deepEqual(fs.readFileSync(path.join(downloads, 'Schoolrooster.pdf')), pdfFixture);
  await ready(`${button('Bevestigen')}?.disabled===false`); await shot('planner-selected-desktop');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 1, mobile: true }); await shot('planner-selected-mobile');
  assert.equal(await evaluate("document.documentElement.scrollWidth <= window.innerWidth && document.querySelector('dialog').getBoundingClientRect().width <= window.innerWidth"), true);
  statusCode = 'ROSTER_ATTACHMENT_UNAVAILABLE'; await click('Bevestigen'); await ready('window.results.length===2');
  assert.equal(requests.at(-1).body.rosterAttachmentMode, 'planner_final'); assert.equal(requests.at(-1).body.rosterSnapshotId, 101);
  assert.match(await evaluate("document.querySelector('#result').textContent"), /niet meer beschikbaar/);
  available = false; await open(); assert.equal(await evaluate("document.querySelectorAll('[name=roster-attachment]').length"), 0); await click('Annuleren');
  available = true; statusCode = 'SUCCESS';
  const before = requests.length; await click('Alleen status wijzigen');
  assert.equal(await evaluate("document.querySelectorAll('dialog [name=roster-attachment]').length"), 0); assert.equal(requests.length, before);
  await click('Bevestigen'); await ready('window.results.length===3'); assert.equal(requests.at(-1).body.mailMode, 'none'); assert(!('rosterSnapshotId' in requests.at(-1).body));
  await target('Afgewezen'); const beforeReject = requests.length; await open(); assert.equal(requests.length, beforeReject); assert.equal(await evaluate("document.querySelectorAll('[name=roster-attachment]').length"), 0); await click('Annuleren');
  await target('Definitief'); optionStatus = 401; await open(); await ready("document.querySelector('dialog [role=alert]')?.textContent.includes('sessie is verlopen')"); await shot('session-error-mobile'); await click('Annuleren');
  optionStatus = 200; await click('Status wijzigen en e-mail sturen'); await evaluate('window.setBooking(3)'); await wait(300);
  assert.equal(await evaluate("document.querySelector('dialog').open"), false); assert.equal(await evaluate("document.querySelectorAll('[name=roster-attachment]').length"), 0);
  await open(); await evaluate("document.querySelector('[value=planner_final]').click()");
  await ready("document.querySelector('dialog').textContent.includes('Schoolrooster voorbereiden')");
  await evaluate('window.setBooking(4)'); await wait(700);
  assert.equal(await evaluate("document.querySelector('dialog').open"), false);
  await open(); assert.equal(await evaluate("document.querySelector('[value=example]').checked"), true);
  assert.equal(await evaluate(`!!${button('Schoolrooster downloaden (PDF)')}`), false);
  await evaluate("document.querySelector('[value=planner_final]').click()");
  await ready("document.querySelector('dialog').textContent.includes('Schoolrooster voorbereiden')");
  await evaluate("document.querySelector('[value=example]').click()"); await wait(700);
  assert.equal(await evaluate("document.querySelector('[value=example]').checked"), true);
  assert.equal(await evaluate(`!!${button('Schoolrooster downloaden (PDF)')}`), false);
  assert.equal(await evaluate(`${button('Bevestigen')}.disabled`), false);
  await click('Annuleren');
  assert.deepEqual(errors, []);
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ passed: true, requests, errors, checks: ['example default/absent', 'final revision', 'POST prepare before download', 'dedicated prepare CSRF+strict body', 'pending/failed/stale prepare blocks confirmation', 'download/error', 'exact request+CSRF', 'stale no fallback', 'none/rejection', 'session error', 'async metadata/prepare booking switch', 'explicit example cancels pending prepare', '375px/desktop'] }, null, 2));
  console.log('PASS: actual Vue browser workflow, private download, defaults, stale/error/no-mail, mobile and async selection.');
} catch (error) {
  fs.writeFileSync(path.join(output, 'failure.json'), JSON.stringify({ error: String(error), requests, downloadEvents, errors, network,
    dom: inspect ? await inspect('document.documentElement.outerHTML') : null }, null, 2));
  throw error;
} finally { socket?.close(); browser?.kill(); await server.close(); }
