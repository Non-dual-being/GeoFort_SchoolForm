// Real Vue/CSS in headless Chrome, with synthetic, read-only HTTP fixtures.
// No PHP/bootstrap, application database, login session, or email is used.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';

const root = fileURLToPath(new URL('../../', import.meta.url));
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'geofort-blocked-planning-browser-'));
const executable = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
assert(fs.existsSync(executable), 'Set CHROME_PATH to an installed Chromium browser.');
const requests = [];
const errors = [];
const browserConsole = [];
const dependencyScripts = new Map();
const removedPlanningDates = new Set();
const iso = date => date.toISOString().slice(0, 10);
const today = new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Amsterdam'}).format(new Date());
const statuses = ['Definitief', 'In optie', 'Afgewezen'].map((value, i) => ({ value, label: value, shortLabel: value, presentation: ['confirmed','option','rejected'][i] }));
function fixture(date) {
  const day = Number(date.slice(-2));
  const weekday = new Date(`${date}T12:00:00Z`).getUTCDay() || 7;
  const status = day === 10 ? 'In optie' : day === 11 ? 'Afgewezen' : 'Definitief';
  const count = !removedPlanningDates.has(date) && [8,9,10,11,13,14].includes(day) ? (day === 9 ? 2 : 1) : 0;
  const students = day === 10 ? 40 : day === 11 ? 200 : day === 13 ? 30 : day === 14 ? 20 : 60;
  const type = day === 8 || day === 11 ? 'manual' : day === 10 ? 'school_vacation' : weekday > 5 ? 'weekend' : null;
  const maximumCapacity = day === 8 ? 100 : 160;
  return { weekday, type, maximumCapacity, aggregates: count ? [{ program: 'dag', status, bookingCount: count, studentCount: students, unknownStudentCount: 0, invalidStudentCount: 0 }] : [] };
}
function dates(start, end) {
  const result = [];
  for (let day = new Date(`${start}T12:00:00Z`); iso(day) <= end; day.setUTCDate(day.getUTCDate() + 1)) result.push(iso(day));
  return result;
}
function overview(year, month) {
  const monthStart = `${year}-${String(month).padStart(2,'0')}-01`;
  const start = new Date(`${monthStart}T12:00:00Z`);
  start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
  const end = new Date(start); end.setUTCDate(end.getUTCDate() + 41);
  return {
    period: { year, month, monthStart, monthEnd: iso(new Date(Date.UTC(year,month,0,12))), gridStart: iso(start), gridEnd: iso(end) },
    generatedAt: '2026-09-18T12:00:00+02:00', timezone: 'Europe/Amsterdam',
    capacity: { totalDaily: 160, programs: { dag: 160, ochtend: 80 } },
    filters: { programs: [{ value:'dag', label:'Dagprogramma' },{ value:'ochtend', label:'Ochtendprogramma' }], statuses },
    days: dates(iso(start),iso(end)).map(date => {
      const { weekday,type,aggregates,maximumCapacity } = fixture(date);
      return { date,weekday,inSelectedMonth:date.slice(0,7)===monthStart.slice(0,7),isPast:date<today,isToday:date===today,isBookableWeekday:weekday<6,
        disabled:type ? { type,source:'planner',label:type==='manual'?'Geblokkeerd':type==='weekend'?'Weekend':'Vakantie' } : null,
        hasBookingsOnBlockedDate:Boolean(type && aggregates.length),capacity:{totalDaily:maximumCapacity,programs:{dag:maximumCapacity,ochtend:80}},aggregates };
    }),
  };
}
function detail(date) {
  const { weekday,type,aggregates,maximumCapacity } = fixture(date);
  const aggregate = aggregates[0];
  const active = aggregate && aggregate.status !== 'Afgewezen';
  const confirmed = aggregate?.status === 'Definitief';
  const isPast = date < today;
  return { date,weekday,isPast,manuallyBlocked:type==='manual',manualBlockReason:type==='manual'?'Testblokkade':null,
    disabledType:type,disabledSource:type?'planner':null,disabledReason:type?'Testblokkade':null,
    canBlockManually:!isPast && !type && weekday<6,canReleaseManualBlock:!isPast && type==='manual',canReleasePlannerBlock:!isPast && (type==='manual'||type==='school_vacation'),
    bookingCount:active?aggregate.bookingCount:0,confirmedBookingCount:confirmed?aggregate.bookingCount:0,optionBookingCount:aggregate?.status==='In optie'?aggregate.bookingCount:0,otherBookingCount:aggregate?.status==='Afgewezen'?aggregate.bookingCount:0,
    studentCount:active?aggregate.studentCount:0,confirmedStudentCount:confirmed?aggregate.studentCount:0,
    maximumCapacity,remainingCapacity:maximumCapacity-(confirmed?aggregate.studentCount:0),programs:active?['Dagprogramma']:[],state:isPast?'past':type?'blocked':active?'limited':'available',warnings:[],
    bookings:aggregate?Array.from({length:aggregate.bookingCount},(_,index)=>({ id:Number(date.slice(-2))+index*100,status:aggregate.status,schoolName:'Synthetische testschool',program:'dag',programLabel:'Dagprogramma',studentCount:aggregate.studentCount/aggregate.bookingCount,active:Boolean(active) })):[] };
}
const server = await createServer({ root, configFile:false, envDir:false, publicDir:false, cacheDir:path.join(output,'vite'),
  // The virtual fixture entry is not found by Vite's normal HTML entry scan.
  // Prebundle its dependencies together so lazy management imports cannot load
  // a second rendering runtime after late dependency discovery.
  optimizeDeps:{ include:['vue','vue-router','lucide-vue-next'] },
  plugins:[vue(),{ name:'synthetic-calendar', configureServer(server) { server.middlewares.use((req,res,next) => {
    const url = new URL(req.url,'http://127.0.0.1');
    if (url.pathname === '/') { res.setHeader('Content-Type','text/html'); res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><div id="app" class="admin-shell"></div><script type="module" src="/fixture-entry.js"></script></body></html>'); return; }
    if (url.pathname.startsWith('/api/')) {
      requests.push({ method:req.method, path:url.pathname, query:url.search });
      assert.equal(req.method,'GET','Browser attempted a mutation.');
      const calendar = url.pathname.endsWith('/overview.php') ? overview(Number(url.searchParams.get('year')),Number(url.searchParams.get('month'))) : {
        startDate:url.searchParams.get('startDate'),endDate:url.searchParams.get('endDate'),managementPolicy:{ reasonMinLength:3,reasonMaxLength:255,maxPeriodDays:366 },
        days:dates(url.searchParams.get('startDate'),url.searchParams.get('endDate')).map(detail),
      };
      res.setHeader('Content-Type','application/json'); res.end(JSON.stringify({ ok:true,data:{ calendar } })); return;
    }
    next();
  }); }, resolveId(id) { if (id === '/fixture-entry.js') return '\0fixture-entry'; }, load(id) { if (id !== '\0fixture-entry') return;
    return `import {createApp,h} from 'vue'; import {createRouter,createWebHashHistory,RouterView,RouterLink} from 'vue-router';
      import Calendar from '/resources/js/admin/views/DashboardCalendarView.vue';
      import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts'; import '/resources/css/admin.css';
      const router=createRouter({history:createWebHashHistory(),routes:[{path:'/calendar',name:'calendar',component:Calendar},{path:'/booking/:id',name:'booking-detail',component:{render:()=>h(RouterLink,{to:{name:'calendar',query:{month:'2027-03'}}},()=> 'Terug naar agenda')}}]});
      const app=createApp({render:()=>h(RouterView)}); app.use(router); app.provide(adminBootstrapKey,{calendarDateManagementCsrfToken:'synthetic-unused'}); app.mount('#app');`;
  }}], server:{host:'127.0.0.1',port:0}, logLevel:'error' });
let browser;
let socket;
let cdp;
async function vueRuntimes() {
  const runtimes = [];
  for (const [scriptId, url] of dependencyScripts) {
    const { scriptSource } = await cdp('Debugger.getScriptSource', { scriptId });
    if (scriptSource.includes('var currentRenderingInstance = null')) runtimes.push(url);
  }
  return [...new Set(runtimes)];
}
const pause = ms => new Promise(resolve => setTimeout(resolve,ms));
async function until(callback, message) {
  const deadline = Date.now()+20000;
  while (Date.now()<deadline) { if (await callback()) return; await pause(100); }
  throw new Error(message);
}
try {
  await server.listen();
  const port = server.httpServer.address().port;
  console.log('Synthetic calendar server ready; starting isolated Chrome.');
  const profile = path.join(output,'chrome');
  browser = spawn(executable,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{ windowsHide:true,stdio:'ignore' });
  await until(() => fs.existsSync(path.join(profile,'DevToolsActivePort')),'Chrome did not start.');
  const debugPort = fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`, { signal: AbortSignal.timeout(5000) })).json();
  socket = new WebSocket(tabs.find(tab=>tab.type==='page').webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ const timer=setTimeout(()=>reject(new Error('CDP connection timed out.')),5000);socket.onopen=()=>{clearTimeout(timer);resolve();};socket.onerror=()=>{clearTimeout(timer);reject(new Error('CDP connection failed.'));}; });
  const pending = new Map(); let sequence = 0;
  socket.onmessage = event => {
    const message=JSON.parse(event.data);
    if(message.id){ const p=pending.get(message.id);if(!p)return;clearTimeout(p.timer);pending.delete(message.id);message.error?p.reject(message.error):p.resolve(message.result); }
    else if(message.method==='Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
    else if(message.method==='Runtime.consoleAPICalled') browserConsole.push({type:message.params.type,text:message.params.args.map(arg=>arg.value??arg.description).join(' ')});
    else if(message.method==='Debugger.scriptParsed' && /\/vite\/deps\/chunk-/.test(message.params.url)) dependencyScripts.set(message.params.scriptId,message.params.url);
  };
  cdp = (method,params={}) => new Promise((resolve,reject)=>{ const id=++sequence;const timer=setTimeout(()=>{pending.delete(id);reject(new Error(`CDP timeout: ${method}`));},10000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params})); });
  const evaluate = async expression => { const result=await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value; };
  const ready = expression => until(()=>evaluate(expression),`Browser condition failed: ${expression}`);
  const key = async (key, code, virtualKey, text) => {
    await cdp('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode:virtualKey,...(text?{text,unmodifiedText:text}:{})});
    await cdp('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode:virtualKey});
  };
  const pointOn = expression => evaluate(`(()=>{const el=${expression};el.scrollIntoView({block:'center',inline:'center'});const r=el.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
  const mouseClick = async expression => {
    const point=await pointOn(expression);
    await cdp('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
    await cdp('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
  };
  const hover = async expression => cdp('Input.dispatchMouseEvent',{type:'mouseMoved',...await pointOn(expression)});
  const tap = async expression => {
    await cdp('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[await pointOn(expression)]});
    await cdp('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  };
  const screenshot = async name => {
    const shot=await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    fs.writeFileSync(path.join(output,`${name}.png`),Buffer.from(shot.data,'base64'));
  };
  const actionStyle = expression => evaluate(`(()=>{const cell=${expression};const button=cell.querySelector('button');const style=getComputedStyle(button,'::after');return {shadow:style.boxShadow,outline:style.outlineStyle,outlineWidth:style.outlineWidth,hint:getComputedStyle(button.querySelector('span')).opacity,border:getComputedStyle(cell).borderStyle};})()`);
  const assertNoOldLabel = async () => assert.doesNotMatch(await evaluate("document.body.textContent + [...document.querySelectorAll('[aria-label],[title]')].map(el=>(el.getAttribute('aria-label')??'')+(el.getAttribute('title')??'')).join(' ')"),/Bestaande planning/i);
  await cdp('Runtime.enable'); await cdp('Page.enable'); await cdp('Debugger.enable');
  await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  await cdp('Page.navigate',{url:`http://127.0.0.1:${port}/#/calendar?month=2027-03`});
  await ready("document.querySelectorAll('[role=gridcell]').length===42");
  assert.match(await evaluate("document.querySelector('[aria-label=Maandsamenvatting]').textContent"),/5\s*Definitief/);
  assert.match(await evaluate("document.querySelector('[aria-label=Maandsamenvatting]').textContent"),/210 leerlingen/);
  const cell = "[...document.querySelectorAll('[role=gridcell]')].find(el=>el.getAttribute('aria-label').startsWith('maandag 8 maart'))";
  const freeCell = "[...document.querySelectorAll('[role=gridcell]')].find(el=>el.getAttribute('aria-label').startsWith('dinsdag 9 maart'))";
  const emptyCell = "[...document.querySelectorAll('[role=gridcell]')].find(el=>el.getAttribute('aria-label').startsWith('vrijdag 12 maart'))";
  const blockedEmptyCell = "[...document.querySelectorAll('[role=gridcell]')].find(el=>el.getAttribute('aria-label').startsWith('zaterdag 6 maart'))";
  assert.match(await evaluate(`${cell}.textContent`),/Geblokkeerd[\s\S]*1\s*Definitief · 60 leerlingen[\s\S]*Bekijk boeking/);
  assert.match(await evaluate(`${freeCell}.textContent`),/2\s*Definitief · 60 leerlingen[\s\S]*Bekijk boekingen/);
  assert.match(await evaluate(`${cell}.querySelector('button').getAttribute('aria-label')`),/^Bekijk boeking\. maandag 8 maart 2027\..*Geblokkeerd/);
  assert.match(await evaluate(`${freeCell}.querySelector('button').getAttribute('aria-label')`),/^Bekijk boekingen\. dinsdag 9 maart 2027\./);
  assert.equal(await evaluate(`${cell}.querySelector('button').getAttribute('aria-haspopup')`),'dialog');
  assert.equal(await evaluate("document.querySelectorAll('[role=gridcell] button :is(button,a,input,[tabindex])').length"),0,'Nested interactive calendar elements.');
  await assertNoOldLabel();
  await screenshot('calendar-desktop-idle');
  for (const [name,expression] of [['blocked',cell],['free',freeCell]]) {
    await hover("document.querySelector('.admin-calendar-page__intro')");
    const idle = await actionStyle(expression);
    assert.equal(idle.hint,'0','Action hints must not permanently label every cell.');
    await hover(`${expression}.querySelector('.admin-calendar-overview-day__number')`);
    const hovered = await actionStyle(expression);
    assert.notEqual(hovered.shadow,idle.shadow,'Hover must visibly identify the clickable day.');
    assert.equal(hovered.hint,'1');
    assert.equal(hovered.border,name==='blocked'?'dashed':'solid');
    // The date, booking badge, centre and padding all hit the same native button.
    const hitTargets = await evaluate(`(()=>{const el=${expression};const r=el.getBoundingClientRect();return [[r.left+4,r.top+4],[r.right-4,r.bottom-4],...[el.querySelector('.admin-calendar-overview-day__number'),el.querySelector('.admin-calendar-overview-badge'),el].map(node=>{const b=node.getBoundingClientRect();return [b.x+b.width/2,b.y+b.height/2];})].map(([x,y])=>{const target=document.elementFromPoint(x,y);return {button:target.closest('button')===el.querySelector('button'),cursor:getComputedStyle(target).cursor};});})()`);
    assert(hitTargets.every(hit=>hit.button && hit.cursor==='pointer'),'The entire indicated surface must open bookings.');
    await screenshot(`calendar-${name}-hover`);
    await mouseClick(`${expression}.querySelector('.admin-calendar-overview-day__number')`);
    await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
    assert.equal(await evaluate("document.querySelector('dialog[open] .admin-dialog__title').textContent.trim()"),'Boekingen op deze dag');
    assert.equal(await evaluate("document.querySelectorAll('dialog[open] .admin-calendar-detail__bookings a').length"),name==='blocked'?1:2);
    await assertNoOldLabel();
    await mouseClick("document.querySelector('dialog[open] .admin-dialog__close')");
    await ready(`document.querySelector('dialog[open]')===null && document.activeElement===${expression}.querySelector('button')`);
  }
  for (const expression of [emptyCell,blockedEmptyCell]) {
    assert.match(await evaluate(`${expression}.textContent`),/Geen boekingen/);
    assert.equal(await evaluate(`${expression}.querySelector('button')`),null);
    assert.doesNotMatch(await evaluate(`${expression}.getAttribute('title')`),/Bekijk boeking/);
    const idle = await evaluate(`getComputedStyle(${expression}).boxShadow`);
    await hover(expression);
    assert.equal(await evaluate(`getComputedStyle(${expression}).boxShadow`),idle,'Empty days must not acquire an action highlight.');
    assert.notEqual(await evaluate(`getComputedStyle(${expression}).cursor`),'pointer');
    const requestCount=requests.length;
    await mouseClick(expression);
    assert.equal(await evaluate("document.querySelector('dialog[open]')"),null);
    assert.equal(requests.length,requestCount);
  }
  assert.equal(await evaluate("[...document.querySelectorAll('[role=gridcell].is-outside')].every(el=>!el.querySelector('button') && !el.title.includes('Bekijk boeking'))"),true);
  await mouseClick("document.querySelector('#admin-select-calendar-status')");
  await ready("document.querySelector('#admin-select-calendar-status-listbox')!==null");
  await mouseClick("[...document.querySelectorAll('#admin-select-calendar-status-listbox [role=option]')].find(el=>el.textContent.trim()==='Definitief')");
  await ready(`${cell}.querySelector('.admin-calendar-overview-day__student-badge')!==null`);
  assert.match(await evaluate(`${cell}.getAttribute('aria-label')`),/gemiddelde bezetting/,'60 of overridden 100 must not use the standard 160 denominator.');
  assert.equal(await evaluate(`${cell}.querySelector('.admin-calendar-overview-day__student-badge').classList.contains('is-occupancy-medium')`),true);
  await hover(freeCell);
  assert.equal(await evaluate(`${freeCell}.classList.contains('has-multiple-bookings') && ${freeCell}.classList.contains('has-status-confirmed')`),true);
  await screenshot('calendar-filtered-hover');
  await mouseClick("document.querySelector('#admin-select-calendar-status')");
  await ready("document.querySelector('#admin-select-calendar-status-listbox')!==null");
  await mouseClick("[...document.querySelectorAll('#admin-select-calendar-status-listbox [role=option]')].find(el=>el.textContent.trim()==='In optie')");
  await ready(`${cell}.textContent.includes('Geen resultaten')`);
  assert.match(await evaluate(`${freeCell}.querySelector('button').getAttribute('aria-label')`),/^Bekijk boekingen\./,'Details still include all bookings when filtered out.');
  await mouseClick(cell);
  await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
  assert.match(await evaluate("document.querySelector('dialog[open]').textContent"),/Synthetische testschool[\s\S]*60 leerlingen/);
  await key('Escape','Escape',27);
  await ready("document.querySelector('dialog[open]')===null");
  await mouseClick("document.querySelector('.admin-calendar-overview__reset')");
  await ready(`${cell}.querySelector('.admin-calendar-overview-day__badges')!==null`);
  for (const width of [1440,768,375]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});
    await pause(200);
    const layout = await evaluate(`(()=>{const cell=${cell}; const state=cell.querySelector('.admin-calendar-overview-day__state'); const label=state.querySelector('span');const button=cell.querySelector('button');const range=document.createRange();range.selectNodeContents(label);return {pageFits:document.documentElement.scrollWidth<=innerWidth,wordRects:range.getClientRects().length,labelFits:label.scrollWidth<=label.clientWidth,buttonFits:button.scrollWidth<=button.clientWidth,wrap:getComputedStyle(button).overflowWrap};})()`);
    assert.equal(layout.pageFits,true,`Page overflow at ${width}`);
    assert.equal(layout.wordRects,1,`Geblokkeerd splits at ${width}`);
    assert.equal(layout.labelFits,true); assert.equal(layout.buttonFits,true); assert.equal(layout.wrap,'normal');
    if (width < 1000) await evaluate(`${cell}.scrollIntoView({block:'center',inline:'start'})`);
    const shot = await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(output,`calendar-${width}.png`),Buffer.from(shot.data,'base64'));
    await hover("document.querySelector('.admin-calendar-page__intro')");
    await evaluate("document.querySelector('[aria-label=\"Volgende maand\"]').focus()");
    for (const [name,expression] of [['blocked',cell],['free',freeCell]]) {
      await key('Tab','Tab',9);
      assert.equal(await evaluate(`document.activeElement === ${expression}.querySelector('button')`),true);
      const focused = await actionStyle(expression);
      assert.equal(focused.outline,'solid'); assert.equal(focused.outlineWidth,'2px');
      assert.equal(focused.hint,'1'); assert.equal(focused.border,name==='blocked'?'dashed':'solid');
      await screenshot(`calendar-${name}-focus-${width}`);
      await key(' ','Space',32,' ');
      await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
      assert.equal(await evaluate("document.querySelectorAll('dialog[open] .admin-calendar-detail__bookings a').length"),name==='blocked'?1:2);
      await key('Escape','Escape',27);
      await ready(`document.querySelector('dialog[open]')===null && document.activeElement===${expression}.querySelector('button')`);
    }
  }
  // Tab reaches the planning action; Enter opens it and Escape restores focus.
  await evaluate("document.querySelector('[aria-label=\"Volgende maand\"]').focus()");
  await key('Tab','Tab',9);
  assert.equal(await evaluate(`document.activeElement === ${cell}.querySelector('button')`),true);
  await key('Enter','Enter',13,'\r');
  await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
  assert.match(await evaluate("document.querySelector('dialog[open]').textContent"),/Synthetische testschool[\s\S]*60 leerlingen/);
  assert.match(await evaluate("document.querySelector('dialog[open]').textContent"),/Dagcapaciteit\s*100/);
  assert.match(await evaluate("document.querySelector('dialog[open] a').getAttribute('href')"),/booking\/8/);
  const detailShot=await cdp('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(output,'day-detail-375.png'),Buffer.from(detailShot.data,'base64'));
  await key('Escape','Escape',27);
  await ready("document.querySelector('dialog[open]')===null");
  await ready("document.activeElement.classList.contains('admin-calendar-overview-day__details')");
  await mouseClick(`${cell}.querySelector('button')`);
  await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
  await mouseClick("document.querySelector('dialog[open] .admin-dialog__close')");
  await ready("document.querySelector('dialog[open]')===null");
  // Real touch input reaches both kinds of booking day without hover.
  await cdp('Emulation.setDeviceMetricsOverride',{width:375,height:812,deviceScaleFactor:1,mobile:true});
  await cdp('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:1});
  await evaluate('document.activeElement.blur()');
  assert.equal(await evaluate("matchMedia('(hover: none) and (pointer: coarse)').matches"),true);
  for (const [name,expression] of [['blocked',cell],['free',freeCell]]) {
    assert.equal((await actionStyle(expression)).hint,'0');
    await tap(`${expression}.querySelector('.admin-calendar-overview-day__number')`);
    await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
    assert.equal(await evaluate("document.querySelectorAll('dialog[open] .admin-calendar-detail__bookings a').length"),name==='blocked'?1:2);
    await screenshot(`calendar-${name}-touch-detail`);
    await tap("document.querySelector('dialog[open] .admin-dialog__close')");
    await ready(`document.querySelector('dialog[open]')===null && document.activeElement===${expression}.querySelector('button')`);
    await screenshot(`calendar-${name}-touch`);
  }
  await cdp('Emulation.setTouchEmulationEnabled',{enabled:false});
  await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  await mouseClick("[...document.querySelectorAll('.admin-calendar-page-mode button')].find(el=>el.textContent.trim()==='Beheer')");
  await ready("document.querySelectorAll('.admin-calendar-day').length===42");
  const heading = await evaluate("document.querySelector('.admin-calendar__header h2').textContent");
  await mouseClick("document.querySelector('[aria-label=\"Volgende maand\"]')");
  await ready(`document.querySelector('.admin-calendar__header h2').textContent!==${JSON.stringify(heading)} && document.querySelector('[aria-busy=true]')===null`);
  const managedCell = "[...document.querySelectorAll('.admin-calendar-day')].find(el=>el.getAttribute('aria-label').includes('1 actieve boekingen')&&el.classList.contains('is-manual')&&!el.classList.contains('is-outside'))";
  await evaluate(`${managedCell}.focus()`);
  await key('Enter','Enter',13,'\r');
  await ready("document.querySelector('.admin-calendar-detail__bookings a')!==null");
  assert.equal(await evaluate("document.querySelector('.admin-calendar-action-card > .admin-button').disabled"),true,'Viewing a block must not enable blocking again.');
  await mouseClick("document.querySelector('.admin-calendar-selection-clear')");
  await mouseClick(managedCell);
  await ready("document.querySelector('.admin-calendar-detail__bookings a')!==null");
  assert.equal(await evaluate("document.querySelector('.admin-calendar-action-card > .admin-button').disabled"),true);
  await assertNoOldLabel();
  await screenshot('calendar-management');
  // Simulate a changed read response after leaving for booking details. There
  // is no mutation endpoint in this fixture: backend changes are tested in PHP.
  await mouseClick("[...document.querySelectorAll('.admin-calendar-page-mode button')].find(el=>el.textContent.trim()==='Overzicht')");
  await ready("document.querySelectorAll('[role=gridcell]').length===42");
  await mouseClick(`${cell}.querySelector('button')`);
  await ready("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')!==null");
  await mouseClick("document.querySelector('dialog[open] .admin-calendar-detail__bookings a')");
  await ready("location.hash.includes('/booking/8') && document.querySelector('.admin-calendar-page')===null");
  removedPlanningDates.add('2027-03-08');
  await mouseClick("[...document.querySelectorAll('a')].find(el=>el.textContent==='Terug naar agenda')");
  await ready("document.querySelector('[aria-label=Maandsamenvatting]')?.textContent.includes('150 leerlingen')");
  assert.match(await evaluate(`${cell}.textContent`),/Geblokkeerd[\s\S]*Geen boekingen/);
  assert.equal(await evaluate(`${cell}.querySelector('button')`),null,'A removed booking still exposes stale planning.');
  assert.match(await evaluate("document.querySelector('[aria-label=Maandsamenvatting]').textContent"),/4\s*bezoekdagen/);
  const runtimes = await vueRuntimes();
  assert.equal(runtimes.length,1,'Fixture loaded multiple Vue rendering runtimes.');
  assert(requests.every(request=>request.method==='GET')); assert.deepEqual(errors,[]);
  assert.deepEqual(browserConsole.filter(entry=>entry.type==='error'),[],'Browser console contains errors.');
  fs.writeFileSync(path.join(output,'checks.json'),JSON.stringify({widths:[1440,768,375],requests,errors,browserConsole,runtimes,result:'passed'},null,2));
  console.log(`Calendar browser checks passed (desktop/tablet/mobile, hover, Enter/Space, focus return, touch, empty/blocked/filtered days, protected management action, cache refresh). Artifacts: ${output}`);
} catch (error) {
  console.error(error);
  if (cdp) {
    const state = await cdp('Runtime.evaluate',{expression:'({body:document.body.innerText,active:document.activeElement?.outerHTML,dialogs:[...document.querySelectorAll("dialog")].map(d=>({open:d.open,html:d.innerHTML}))})',returnByValue:true});
    fs.writeFileSync(path.join(output,'failure.json'),JSON.stringify({requests,errors,browserConsole,runtimes:await vueRuntimes(),state:state.result.value},null,2));
    console.error(`Failure artifacts: ${output}`);
  }
  throw error;
} finally {
  if(cdp) { try { await cdp('Browser.close'); } catch {} }
  socket?.close(); browser?.kill(); await server.close();
}
