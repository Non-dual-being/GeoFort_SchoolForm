// Real Vue wizard and styles with synthetic HTTP; no application DB, mail or deployment.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';

const root = fileURLToPath(new URL('../../', import.meta.url));
const output = process.env.ROSTER_DAY_TEST_OUTPUT ?? fs.mkdtempSync(path.join(os.tmpdir(), 'geofort-day-wizard-'));
fs.mkdirSync(output, { recursive: true });
const rounds = [
  { type: 'welcome', label: 'Welkom', startTime: '10:00', endTime: '10:15' },
  { type: 'round', label: 'Ronde 1', startTime: '10:15', endTime: '11:00' },
  { type: 'round', label: 'Ronde 2', startTime: '11:00', endTime: '11:45' },
  { type: 'departure', label: 'Vertrek', startTime: '11:45', endTime: '12:00' },
];
const modules = ['Earth-Watch', 'Stop-de-Klimaat-Klok'].map((key, i) => ({ key, label: i ? 'Stop de Klimaat Klok' : 'Earth Watch', color: '#dceaff', maxParallel: 2, minimumGeoFortStaff: 1, schoolSupervisionAllowed: false, defaultLocation: null }));
function makePlan(id, name, date = '2028-10-10') {
  return { id, bookingId: null, planningMode: 'custom', visitDate: date, status: 'concept', revision: 1, sourceCurrent: true, needsReview: false,
    school: { name, city: 'Testplaats', contactName: '', contactPhone: '', supervisorCount: null },
    education: { sector: 'primairOnderwijs', sectorLabel: 'Primair onderwijs', program: 'dag', programLabel: 'Dagprogramma', studentCount: 40, choiceModule: null, choiceModuleLabel: null },
    groups: [1, 2].map((n) => ({ id: id * 10 + n, label: 'Groep ' + n, position: n, studentCount: 20 })),
    planning: { modules: structuredClone(modules), rounds: structuredClone(rounds), roundsSource: 'saved', sessions: [], generation: { defaultRounds: rounds.filter(b => b.type === 'round'), templateNote: '' }, staffCatalog: [], selectedStaffIds: [], staffingSettings: { staffingMode: 'lesson_only', preferGeoFortKe: false, cookStaffId: null } },
  };
}
const plans = new Map([[1, makePlan(1, 'School Alpha')], [2, makePlan(2, 'School Beta')], [3, makePlan(3, 'Andere datum', '2028-10-11')]]);
plans.get(1).bookingId = 101;
plans.get(2).bookingId = 102;
const bookings = [101,102].map((id,i)=>({id, schoolName:i ? 'School Beta':'School Alpha', visitDate:'2028-10-10',city:'Testplaats',programLabel:'Dagprogramma',status:i ? 'Definitief':'In optie',studentCount:40}));
const requests = [], errors = [];
let previewStatus = 'candidate_found';
let previewFailure = false;
const config = {
  schoolTypes: [{ value: 'primairOnderwijs', label: 'Primair onderwijs' }],
  programs: { dag: { label: 'Dagprogramma', allowedSchoolTypes: ['primairOnderwijs'], allowedWeekdays: [1, 2, 3, 4, 5], description: ['Dagprogramma'], beginTijd: '10:00', eindTijd: '15:00', duurLesmodule: '00:45' } },
  modules: { primairOnderwijs: { dag: { standaard: modules.map(m => m.key), keuze: [] } } },
  moduleLabels: Object.fromEntries(modules.map(m => [m.key, m.label])),
};
const server = await createServer({ root, configFile: false, envDir: false, publicDir: false, cacheDir: path.join(output, 'vite'),
  optimizeDeps: { entries: [], include: ['vue', 'vue-router', 'lucide-vue-next'] }, logLevel: 'error',
  plugins: [vue(), {
    name: 'synthetic-day-wizard',
    configureServer(server) { server.middlewares.use((req, res, next) => {
      const url = new URL(req.url, 'http://127.0.0.1');
      if (url.pathname === '/') {
        res.setHeader('Content-Type', 'text/html');
        res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/day-fixture.js"></script></body></html>'); return;
      }
      if (!url.pathname.startsWith('/api/')) { next(); return; }
      let body = ''; req.on('data', chunk => body += chunk); req.on('end', () => {
        const send = (data, status = 200) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(data)); };
        const ok = data => send({ ok: true, data });
        try {
          const payload = body ? JSON.parse(body) : null;
          requests.push({ method: req.method, path: url.pathname, query: url.search, payload });
          if (url.pathname.endsWith('/joint-optimize.php')) {
            assert.equal(req.headers['x-csrf-token'],'joint-optimize-token');
            assert.deepEqual(payload.plans.map(p=>p.id),[1,2]);
            if(payload.mode==='apply') { assert.equal(payload.signature,'a'.repeat(64)); for(const p of payload.plans) plans.get(p.id).revision++; }
            const person={staffId:1,name:'Gezamenlijke docent',gapMinutes:45,workMinutes:90,cookMinutes:0};
            ok({signature:'a'.repeat(64),improved:true,applied:payload.mode==='apply',searchLimited:false,
              before:{paidGapMinutes:45,volunteerGapMinutes:0,people:2,staff:[person]},
              after:{paidGapMinutes:0,volunteerGapMinutes:45,people:2,staff:[{...person,gapMinutes:0,workMinutes:135}]},
              changes:[{school:2,sessionId:1,startTime:'10:15',endTime:'11:00',module:'Earth-Watch',role:'Begeleider',from:'Vrijwilliger',to:'Gezamenlijke docent'}]});return;
          }
          if (url.pathname.endsWith('/getBookingProgramConfig.php')) { ok(config); return; }
          if (url.pathname.endsWith('/getDisabledDates.php')) { ok({ minDate: '2028-10-01', maxDate: '2028-10-31', disabledDates: [], details: [], availabilityDetails: [] }); return; }
          if (url.pathname.endsWith('/requests/index.php')) {
            assert.equal(url.searchParams.get('twoSchoolDaysOnly'),'1','Every school selection request must be limited to two-school days');
            if (url.searchParams.has('dateFrom')) {
              assert.equal(url.searchParams.get('dateFrom'),'2028-10-10');
              assert.equal(url.searchParams.get('dateTo'),'2028-10-10');
            }
            ok({items:bookings,pagination:{currentPage:1,totalPages:1,totalItems:2,perPage:20,from:1,to:2}}); return;
          }
          if (url.pathname.endsWith('/rosters/index.php')) { ok({ items: [...plans.values()].map(p => ({ id: p.id, bookingId: p.bookingId, schoolName: p.school.name, visitDate: p.visitDate, status: p.status })) }); return; }
          if (url.pathname.endsWith('/rosters/show.php')) { ok({ plan: plans.get(Number(url.searchParams.get('id'))) }); return; }
          if (url.pathname.endsWith('/rosters/create.php')) {
            assert.equal(req.headers['x-csrf-token'], 'plan-only-token');
            assert.equal(payload.visitDate, plans.get(1).visitDate);
            const p = makePlan(2, payload.schoolName, payload.visitDate); p.planning.modules = []; plans.set(2, p);
            ok({ created: true, plan: p }); return;
          }
          if (url.pathname.endsWith('/rosters/manual-program-save.php')) {
            assert.equal(req.headers['x-csrf-token'], 'plan-only-token');
            const p = plans.get(payload.planId); assert.equal(payload.expectedRevision, p.revision);
            if (payload.moduleKeys) p.planning.modules = structuredClone(modules);
            if (payload.rounds) p.planning.rounds = payload.rounds;
            p.revision++; ok({ plan: p }); return;
          }
          if (url.pathname.endsWith('/rosters/pair-link.php')) {
            assert.equal(req.headers['x-csrf-token'], 'pair-link-token');
            assert.equal(payload.confirmRestart,true);
            const pair={id:1,revision:1,visitDate:'2028-10-10',workflow:payload.workflow,planIds:payload.plans.map(p=>p.id)};
            for(const item of payload.plans){const p=plans.get(item.id);assert.equal(p.revision,item.revision);p.jointPlanning=pair;p.planning.sessions=[];p.revision++;}
            ok(pair);return;
          }
          if (url.pathname.endsWith('/rosters/day-lessons-apply.php')) {
            assert.equal(req.headers['x-csrf-token'], 'day-lessons-token');
            const target = plans.get(payload.plans.at(-1).id);
            assert.equal(target.planning.sessions.length, 0);
            target.planning.sessions = modules.map((m,i) => ({ id: target.id*100+i, moduleKey: m.key, moduleLabel: m.label, color: m.color, startTime: rounds[i+1].startTime, endTime: rounds[i+1].endTime,
              groupIds: target.groups.map(g=>g.id), groupLabels: target.groups.map(g=>g.label), location: null, minimumGeoFortStaff: 1, schoolSupervisionAllowed: false, staffAssignments: [] }));
            target.revision++;
            ok({}); return;
          }
          if (url.pathname.endsWith('/rosters/session-save.php')) {
            assert.equal(req.headers['x-csrf-token'],'session-token');
            const p=plans.get(payload.planId);assert.equal(payload.expectedRevision,p.revision);
            const session=p.planning.sessions.find(s=>s.id===payload.sessionId);
            session.location=payload.location;p.revision++;ok({warnings:[]});return;
          }
          if (url.pathname.endsWith('/rosters/day-preview.php')) {
            assert.equal(req.method, 'GET');
            const selected = url.searchParams.get('planIds').split(',').map(Number);
            assert.equal(url.searchParams.get('revisions'), selected.map(id => plans.get(id).revision).join(','));
            const status = selected.length === 1 ? 'candidate_found' : previewStatus;
            if (previewFailure) { send({ ok: false, code: 'UNAUTHENTICATED' }, 401); return; }
            ok({ savedControl: { selectedPlanIds: selected, canFinalize: false,
              plans: Object.fromEntries(selected.map(id => [id, { planId: id, revision: plans.get(id).revision, valid: false, errors: [{code:'GUIDES_REQUIRED',severity:'error',message:'Begeleider ontbreekt.'}], warnings: [] }])),
              day: { visitDate: '2028-10-10', errors: [], warnings: [] } },
              lessons: { status, checkedCombinations: 2, errors: [], conflicts: status === 'candidate_found' ? [] : [{ message: 'Earth Watch overschrijdt de gezamenlijke capaciteit.' }],
                schools: status === 'candidate_found' ? selected.map(id => ({ planId: id, schoolName: plans.get(id).school.name, isProposal: plans.get(id).planning.sessions.length === 0, sessions: modules.map((m,i) => ({ moduleKey: m.key, moduleLabel: m.label, startTime: rounds[i+1].startTime, endTime: rounds[i+1].endTime, groupLabels: ['Groep 1','Groep 2'] })) })) : [] } }); return;
          }
          throw new Error('Unexpected API: ' + url.pathname);
        } catch (e) { errors.push(String(e)); send({ ok: false, message: String(e) }, 500); }
      });
    }); },
    resolveId(id) { if (id === '/day-fixture.js') return '\0day-fixture'; },
    load(id) { if (id === '\0day-fixture') return `import {createApp,h} from 'vue';import {createRouter,createWebHashHistory,RouterView} from 'vue-router';import Day from '/resources/js/admin/views/DashboardRosterDayCreateView.vue';import Detail from '/resources/js/admin/views/DashboardRosterDetailView.vue';import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts';import '/resources/css/admin.css';const router=createRouter({history:createWebHashHistory(),routes:[{path:'/roosters/nieuw',name:'roster-create',component:Day},{path:'/roosters/:id',name:'roster-detail',component:Detail},{path:'/roosters',name:'rosters',component:{render:()=>h('p','Roosters')}}]});const app=createApp({render:()=>h(RouterView)});app.use(router);app.provide(adminBootstrapKey,{rosterSessionCsrfToken:'session-token',rosterPlanCsrfToken:'plan-only-token',rosterDayLessonsCsrfToken:'day-lessons-token',rosterPairLinkCsrfToken:'pair-link-token',rosterJointOptimizeCsrfToken:'joint-optimize-token'});app.mount('#app');`; },
  }], server: { host: '127.0.0.1', port: 0, watch: null, hmr: false } });

let browser, socket;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, message) { const end = Date.now() + 25000; while (Date.now() < end) { if (await fn()) return; await pause(100); } throw new Error(message); }
try {
  await server.listen(); const port = server.httpServer.address().port;
  const profile = fs.mkdtempSync(path.join(output, 'chrome-'));
  browser = spawn(process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'], { windowsHide: true, stdio: 'ignore' });
  await until(() => fs.existsSync(path.join(profile,'DevToolsActivePort')), 'Chrome did not start');
  const debugPort = fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  socket = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve,reject) => { socket.onopen = resolve; socket.onerror = reject; });
  const pending = new Map(); let sequence = 0;
  socket.onmessage = event => {
    const m = JSON.parse(event.data);
    if (m.id) { const p = pending.get(m.id); if (!p) return; clearTimeout(p.timer); pending.delete(m.id); m.error ? p.reject(m.error) : p.resolve(m.result); }
    else if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
    else if (m.method === 'Runtime.consoleAPICalled' && ['error','warning'].includes(m.params.type)) errors.push(m.params.args.map(a => a.value ?? a.description).join(' '));
  };
  const cdp = (method, params={}) => new Promise((resolve,reject) => { const id=++sequence; const timer=setTimeout(()=>reject(new Error('CDP timeout: '+method)),10000); pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params})); });
  const evaluate = async expression => { const r=await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value; };
  const ready = expression => until(()=>evaluate(expression),'Browser condition failed: '+expression);
  const button = label => `[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===${JSON.stringify(label)})`;
  const click = async label => { await ready(`!!${button(label)}`); await evaluate(`${button(label)}.click()`); };
  const screenshot = async name => { const r=await cdp('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(output,name+'.png'),Buffer.from(r.data,'base64')); };
  const navigate = async hash => { await cdp('Page.navigate',{url:`http://127.0.0.1:${port}/#${hash}`}); };
  await cdp('Runtime.enable'); await cdp('Page.enable');
  await cdp('Emulation.setDeviceMetricsOverride',{width:1360,height:1000,deviceScaleFactor:1,mobile:false});
  await navigate('/roosters/nieuw'); await ready("document.body.textContent.includes('Hoeveel scholen')");
  await screenshot('school-count'); await click('Eén school plannen');
  await ready("document.body.textContent.includes('School 1 instellen')");
  await navigate('/roosters/nieuw'); await click('Twee scholen plannen');
  await ready("document.querySelectorAll('.admin-roster-day-picker__booking').length===2");
  assert.equal(await evaluate("document.body.textContent.includes('Bestaand schoolrooster kiezen') || document.body.textContent.includes('Andere datum')"),false,'General roster list must not bypass booking eligibility');
  assert.equal(await evaluate("parseFloat(getComputedStyle(document.querySelector('.admin-roster-day-picker__day')).borderLeftWidth)>=4"),true,'Date group needs a visible shared frame');
  await screenshot('eligible-school-1');
  await evaluate("[...document.querySelectorAll('.admin-roster-day-picker__booking')].find(b=>b.textContent.includes('School Alpha')).click()");
  await ready("document.body.textContent.includes('Twee scholen koppelen') && location.hash.includes('school2=2')");
  assert.equal(await evaluate("document.body.textContent.includes('Schoolrooster bewerken') || document.body.textContent.includes('Passen de lesroosters samen?')"),false,'No standalone editing or premature control before pairing');
  // A single companion was selected automatically. With two choices, require a choice but no search bar.
  bookings.push({...bookings[1], id:103, schoolName:'School Gamma'});
  await navigate('/roosters/nieuw?schools=2&school1=1');
  await ready("document.querySelectorAll('.admin-roster-day-picker__booking').length===2");
  assert.equal(await evaluate("!!document.querySelector('.admin-roster-day-picker input[type=search]')"),false,'School 2 must not show a search bar');
  assert.equal(await evaluate("location.hash.includes('school2=')"),false,'Multiple companions must not be chosen automatically');
  await screenshot('school-2-small-choice');
  await evaluate("[...document.querySelectorAll('.admin-roster-day-picker__booking')].find(b=>b.textContent.includes('School Beta')).click()");
  await click('Scholen koppelen');
  assert.equal(await evaluate(`${button('Leegmaken en scholen koppelen')}.disabled`),true,'Restart requires explicit approval');
  await click('Annuleren');
  assert.equal(requests.filter(r=>r.path.endsWith('/pair-link.php')).length,0,'Cancel wrote to backend');
  await click('Scholen koppelen');
  await evaluate("document.querySelector('.pair-controls input[type=checkbox]').click()");
  await click('Leegmaken en scholen koppelen');
  await ready("document.querySelectorAll('.admin-roster-day-create__choices button').length===2");
  assert.equal(await evaluate("document.body.textContent.includes('Lesindeling school 1 maken') || document.body.textContent.includes('Passende lesindeling school 2 opslaan')"),false,'Redundant generation controls must be removed');
  assert.equal(await evaluate(`${button('Gezamenlijk overzicht openen')}.disabled`),true,'Joint overview must wait for saved lesson/personnel drafts');
  // Both wizard entry points work before either school has lessons.
  for (const id of [2,1]) {
    await evaluate(`[...document.querySelectorAll('.admin-roster-day-create__choices button')][${id-1}].click()`);
    await ready(`location.hash.includes('wizard=${id}') && !!document.querySelector('[aria-label="Wizard gekoppelde scholen"]')`);
    await click('Terug naar gezamenlijke controle');
  }
  await evaluate("location.hash += '&joint=1'");
  await ready("document.body.textContent.includes('Het gezamenlijke overzicht wordt beschikbaar')");
  assert.equal(await evaluate("!!document.querySelector('.joint-overview')"),false,'Direct URL must not bypass closed overview');
  await evaluate("location.hash = location.hash.replace('&joint=1','')");
  await ready("!!document.querySelector('.joint-checks')");
  assert.equal(await evaluate("document.querySelectorAll('.joint-checks__item').length"),2,'Checks must share one panel with two sections');
  const saveFixtureLessons = id => {
    const target=plans.get(id);
    target.planning.sessions=modules.map((m,i)=>({id:id*100+i,moduleKey:m.key,moduleLabel:m.label,color:m.color,startTime:rounds[i+1].startTime,endTime:rounds[i+1].endTime,groupIds:target.groups.map(g=>g.id),groupLabels:target.groups.map(g=>g.label),location:null,minimumGeoFortStaff:1,schoolSupervisionAllowed:false,staffAssignments:[]}));
    target.revision++;
  };
  saveFixtureLessons(1);
  await click('Beide roosters opnieuw controleren');
  await ready("document.body.textContent.includes('Een gezamenlijke lesindeling past')");
  const firstBeforeSecond=JSON.stringify(plans.get(1));
  assert.equal(await evaluate("location.hash.includes('school1=1')&&location.hash.includes('school2=2')"),true,'URL preserves school selection');
  assert.equal(await evaluate("!document.body.textContent.includes('School Beta: Begeleider ontbreekt.')"),true,'Uncreated second grid must not show missing staffing');
  saveFixtureLessons(2);
  await click('Beide roosters opnieuw controleren');
  await ready("document.body.textContent.includes('School Beta: Begeleider ontbreekt.')");
  assert.equal(await evaluate("document.body.textContent.includes('School Alpha: Begeleider ontbreekt.') && document.body.textContent.includes('School Beta: Begeleider ontbreekt.')"),true,'Personnel issues must identify their school');
  for(const width of [1360,390]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Wizard must fit viewport'); await screenshot('joint-control-'+width);
    await evaluate("document.querySelector('.joint-checks').scrollIntoView({block:'start'})");
    await screenshot('joint-status-panel-'+width);
  }
  plans.get(2).revision++; previewStatus='no_candidate_found'; await click('Beide roosters opnieuw controleren');
  await ready("document.body.textContent.includes('Geen passende gezamenlijke lesindeling gevonden.')");
  assert.equal(await evaluate("document.body.textContent.includes('Dit bewijst nog niet') && !document.body.textContent.includes('Een gezamenlijke lesindeling past')"),true,'Failed search must remove old green result and avoid impossibility claim');
  previewFailure=true; await click('Beide roosters opnieuw controleren');
  await ready("document.body.textContent.includes('Je sessie is verlopen')");
  assert.equal(await evaluate("!document.body.textContent.includes('Geen passende gezamenlijke lesindeling gevonden.')"),true,'Failed request must not retain old control');
  previewFailure=false; previewStatus='candidate_found'; await click('Beide roosters opnieuw controleren');
  await ready("document.body.textContent.includes('Een gezamenlijke lesindeling past')");
  await evaluate("[...document.querySelectorAll('button')].find(a=>a.textContent.trim()==='Wizard starten').click()");
  await ready("!!document.querySelector('[aria-label=\"Wizard gekoppelde scholen\"]') && location.hash.includes('wizard=1')");
  assert.equal(await evaluate("document.querySelector('#joint-visit-date').textContent.includes('10 oktober 2028')"),true,'Shared long Dutch date missing');
  await click('Terug naar gezamenlijke controle');
  await ready("document.body.textContent.includes('Een gezamenlijke lesindeling past')");
  assert.equal(JSON.stringify(plans.get(1)),firstBeforeSecond,'School 1 must remain unchanged while selecting and preparing school 2');
  assert.equal(requests.filter(r=>r.path.endsWith('/create.php')).length,0,'Existing school 1 and 2 must be opened, not recreated');
  // One staff identity across schools, with shifted starts and explicit group labels.
  for(const p of plans.values()) {
    p.planning.selectedStaffIds=[77];
    for(const session of p.planning.sessions) session.staffAssignments=[{id:77,name:'Gezamenlijke docent'}];
    p.revision++;
  }
  // Morning school A and longer school B; shared staff must not show a break during another lesson.
  for(const session of plans.get(1).planning.sessions) session.staffAssignments.push({id:79,name:'Alleen ochtend'});
  for(const session of plans.get(2).planning.sessions) session.staffAssignments.push({id:88,name:'Alleen dag'});
  plans.get(2).planning.rounds[0].endTime='10:25';
  plans.get(2).planning.rounds=plans.get(2).planning.rounds.filter(r=>r.type!=='departure');
  plans.get(2).planning.rounds.push({type:'lunch',label:'Lunch',startTime:'12:30',endTime:'13:00'});
  plans.get(2).planning.sessions[1].endTime='12:30';
  const shifted=plans.get(2).planning.sessions[0];
  const [hour,minute]=shifted.startTime.split(':').map(Number);const value=hour*60+minute+10;
  shifted.startTime=String(Math.floor(value/60)).padStart(2,'0')+':'+String(value%60).padStart(2,'0');
  await click('Beide roosters opnieuw controleren');
  await ready(`!!${button('Gezamenlijk overzicht openen')} && !${button('Gezamenlijk overzicht openen')}.disabled`);
  await click('Gezamenlijk overzicht openen');
  await ready("!!document.querySelector('.joint-overview__table')");
  assert.equal(await evaluate("[...document.querySelectorAll('.joint-overview__table thead th')].filter(e=>e.textContent==='Gezamenlijke docent').length"),1,'Shared staff duplicated across schools');
  assert.equal(await evaluate("!!document.querySelector('.joint-overview__assignment--1') && !!document.querySelector('.joint-overview__assignment--2')"),true);
  assert.equal(await evaluate(`document.querySelector('.joint-overview__table').textContent.includes(${JSON.stringify(shifted.startTime)})`),true,'Shifted overlap boundary omitted');
  assert.equal(await evaluate("[...document.querySelectorAll('.joint-overview__assignment:not(.joint-overview__neutral) small')].every(e=>e.textContent.includes('Groep'))"),true);
  const neutralCheck=await evaluate(`(()=>{
    const table=document.querySelector('.joint-overview__table table');
    const headings=[...table.querySelectorAll('thead th')].map(e=>e.textContent);
    const cells=(time)=>[...table.querySelectorAll('tbody tr')].find(r=>r.querySelector('th').textContent.startsWith(time)).querySelectorAll('td');
    const text=(time,name)=>cells(time)[headings.indexOf(name)-1].textContent;
    return {shared:text('10:15','Gezamenlijke docent'),day:text('10:15','Alleen dag'),morning:text('12:30','Alleen ochtend'),lunch:text('12:30','Alleen dag'),colspan:!!table.querySelector('tbody [colspan]')};
  })()`);
  const mergedWelcome=await evaluate(`(()=>{
    const table=document.querySelector('.joint-overview__table table');
    const i=[...table.querySelectorAll('thead th')].findIndex(e=>e.textContent==='Gezamenlijke docent')-1;
    const cell=table.querySelector('tbody tr').querySelectorAll('td')[i];
    return {count:cell.querySelectorAll('.joint-overview__neutral').length,text:cell.textContent,split:!!cell.querySelector('.joint-overview__assignment--shared')};
  })()`);
  assert.equal(mergedWelcome.count,1);assert(mergedWelcome.split && mergedWelcome.text.includes('[School 1] · [School 2]'));
  assert(await evaluate("![...document.querySelectorAll('.joint-overview__assignment')].some(e=>e.textContent.includes('School Alpha')||e.textContent.includes('School Beta'))"),'Long school names clutter cells');
  assert(await evaluate("[...document.querySelectorAll('.joint-overview__assignment:not(.joint-overview__neutral) small')].every(e=>e.textContent.includes('(Groep'))"),'Group parentheses missing');
  for(const width of [1360,390]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
    await evaluate("document.querySelector('#joint-teachers-title').scrollIntoView()");
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Compact joint grid overflows viewport');
    await screenshot('joint-compact-teachers-'+width);
  }
  assert(!neutralCheck.shared.includes('Welkom'),'School welcome obscures lesson at other school');
  assert(neutralCheck.day.includes('Welkom'),'Individual school welcome missing');
  assert.equal(neutralCheck.morning.trim(),'Vrij','Morning-only teacher inherits other school lunch');
  assert(neutralCheck.lunch.includes('Lunch')&&!neutralCheck.colspan,'Lunch is not displayed per teacher');
  assert.equal(await evaluate("document.querySelectorAll('.joint-overview__schools button').length"),0,'View contains edit buttons');
  const boxes=await evaluate("[...document.querySelectorAll('.joint-overview__school')].map(e=>{const r=e.getBoundingClientRect();return {top:r.top,bottom:r.bottom,left:r.left}})");
  assert(boxes[1].top>=boxes[0].bottom && boxes[0].left===boxes[1].left,'School schedules are not stacked');
  await click('Beheer');
  await click('School 2 · School Beta');
  await ready("!!document.querySelector('[aria-label=\"Gezamenlijk handmatig beheer\"]') && !!document.querySelector('[aria-label^=\"Volledig bewerken\"]')");
  assert(await evaluate("location.hash.includes('joint=1') && !location.hash.includes('wizard=')"),'Manual management left joint workspace');
  assert.equal(await evaluate("document.querySelectorAll('.joint-overview__manage .admin-roster-teacher-grid').length"),0,'Individual teacher schedule in joint management');
  assert(await evaluate("!document.querySelector('.joint-overview [aria-label=\"Roosterbeheer\"]')"),'Joint management exposes generator');
  assert(await evaluate("document.querySelector('.joint-overview__manage h1').textContent.includes('School Beta')"),'Wrong school opened');
  const beforeOptimization=JSON.stringify([...plans.values()]);
  await click('Optimalisatievoorstel maken');
  await ready("document.body.textContent.includes('Voorstel voor beide scholen')");
  assert.equal(JSON.stringify([...plans.values()]),beforeOptimization,'Preview mutated a school');
  assert(await evaluate("document.querySelector('.joint-optimization').textContent.includes('45 → 0')"),'Before/after metric missing');
  await evaluate("document.querySelector('.joint-optimization').scrollIntoView()");
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Optimization overflows mobile viewport');
  await screenshot('joint-optimization-preview');
  await click('Optimalisatie toepassen op beide scholen');
  await ready("document.body.textContent.includes('De personeelsinzet van beide scholen is samen bijgewerkt.')");
  await ready("!document.querySelector('.joint-optimization__result')");
  const firstBeforeManual=JSON.stringify(plans.get(1));
  const checksBefore=requests.filter(r=>r.path.endsWith('/day-preview.php')).length;
  await evaluate("document.querySelector('.joint-overview [aria-label^=\"Volledig bewerken\"]').click()");
  await ready("!!document.querySelector('.joint-overview .admin-roster-cell-editor input[maxlength=\"160\"]')");
  await evaluate("(()=>{const input=document.querySelector('.joint-overview .admin-roster-cell-editor input[maxlength=\"160\"]');input.value='Gezamenlijke wijziging';input.dispatchEvent(new Event('input',{bubbles:true}));})()");
  await click('Opslaan');
  await until(()=>requests.filter(r=>r.path.endsWith('/day-preview.php')).length>checksBefore,'Manual save did not refresh joint validation');
  assert.equal(plans.get(2).planning.sessions[0].location,'Gezamenlijke wijziging');
  assert.equal(JSON.stringify(plans.get(1)),firstBeforeManual,'Manual edit mutated other school');
  await click('Bekijk');
  await ready("document.querySelectorAll('.joint-overview__school').length === 2");
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(output,'result.json'),JSON.stringify({status:'PASS',requests:requests.length,createRequests:0,desktopAndMobile:true},null,2));
  console.log('PASS: one/two schools, direct eligible bookings, existing rosters for both schools, fixed date, reload resume, current revisions, distinct lesson/staff states, errors, return link and responsive layout');
} catch (error) {
  console.error(error);
  fs.writeFileSync(path.join(output,'failure.txt'),String(error));
  process.exitCode = 1;
} finally { socket?.close(); browser?.kill(); await server.close(); }
