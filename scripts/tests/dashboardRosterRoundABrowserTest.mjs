// Real detail view and CSS against synthetic HTTP responses; no PHP/application DB writes.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';


const root = fileURLToPath(new URL('../../', import.meta.url));
const output = process.env.ROSTER_ROUND_A_OUTPUT ?? fs.mkdtempSync(path.join(os.tmpdir(), 'geofort-round-a-browser-'));
fs.mkdirSync(output,{recursive:true});
const executable = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
assert(fs.existsSync(executable),'Set CHROME_PATH to installed Chromium.');
const cases=JSON.parse(fs.readFileSync(path.join(root,'scripts/tests/fixtures/RosterPresentationCases.json'),'utf8'));
const requests=[], errors=[], browserConsole=[], dependencyScripts=new Map();
const fixture=cases[0];
function plan(id=1, mode='booking_locked', sessions=fixture.sessions) {
  return {id,bookingId:100,planningMode:mode,visitDate:'2028-10-11',status:'concept',revision:11,needsReview:false,sourceCurrent:true,
    school:{name:`Synthetische school voor Ronde A #${id}`,city:'Voorbeeldstad',contactName:'Sam Voorbeeld',contactPhone:'0612345678',supervisorCount:4},
    education:{sector:'primairOnderwijs',sectorLabel:'Primair onderwijs',program:'dag',programLabel:'Dagprogramma',choiceModule:null,choiceModuleLabel:null,studentCount:84},
    groups:fixture.groups,planning:{sessions,rounds:[],modules:[...new Map(sessions.map(s=>[s.moduleKey,{key:s.moduleKey,label:s.moduleLabel,color:s.color,maxParallel:10,minimumGeoFortStaff:s.minimumGeoFortStaff,schoolSupervisionAllowed:s.schoolSupervisionAllowed}])).values()],
      // The presentation-only fixture omits skills. Lifecycle UI needs real personnel capabilities.
      generation:{defaultRounds:[],templateNote:''},catalogRevision:1,
      staffCatalog:fixture.staffCatalog.map(member=>({...member,preferences:member.canGuide?[...new Set(sessions.map(session=>session.moduleKey))].map(moduleKey=>({moduleKey,moduleLabel:moduleKey,rank:1})):[]})),
      selectedStaffIds:fixture.selectedStaffIds,
      staffingSettings:{staffingMode:'with_staff',preferGeoFortKe:false,cookStaffId:fixture.cookStaffId}}};
}
const plans=new Map([[1,plan()],[2,plan(2,'booking_locked',[])],[3,plan(3,'custom',[])],[4,{...plan(4),status:'checked'}],[5,plan(5,'custom')]]);
const actualPlan=process.env.ROSTER_ROUND_A_PLAN ? JSON.parse(fs.readFileSync(process.env.ROSTER_ROUND_A_PLAN,'utf8')) : null;
if(actualPlan) plans.set(actualPlan.id,actualPlan);
let validationRevisionOverride=null, conflictOnce=false, validationError=false, exportError=false;
const server=await createServer({root,configFile:false,envDir:false,publicDir:false,cacheDir:path.join(output,'vite'),
  optimizeDeps:{entries:[],include:['vue','vue-router','lucide-vue-next']},plugins:[vue(),{
    name:'synthetic-roster-round-a',
    configureServer(server){server.middlewares.use((req,res,next)=>{
      const url=new URL(req.url,'http://127.0.0.1');
      if(url.pathname==='/'){res.setHeader('Content-Type','text/html');res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/fixture-entry.js"></script></body></html>');return;}
      if(!url.pathname.startsWith('/api/')){next();return;}
      const request={method:req.method,path:url.pathname,query:url.search,token:req.headers['x-csrf-token']??null,body:null};requests.push(request);
      let body='';req.on('data',chunk=>body+=chunk);req.on('end',async()=>{
        const send=(data,status=200)=>{res.statusCode=status;res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
        try {
          request.body=body?JSON.parse(body):null;
          const id=Number(url.searchParams.get('id')??url.searchParams.get('planId')??request.body?.planId);
          const current=plans.get(id);
          if(url.pathname.endsWith('/show.php')){send({ok:true,data:{plan:current}});return;}
          await new Promise(resolve=>setTimeout(resolve,650));
          if(url.pathname.endsWith('/validate.php')){
            if(validationError){send({ok:false,message:'Sessie verlopen',code:'SESSION_EXPIRED'},401);return;}
            send({ok:true,data:{validation:{planId:id,revision:validationRevisionOverride??current.revision,valid:true,errors:[],warnings:[]}}});return;
          }
          if(url.pathname.endsWith('/export-xlsx.php')){
            if(exportError){send({ok:false,message:'Synthetische exportfout'},500);return;}
            res.setHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            res.setHeader('Content-Disposition','attachment; filename="synthetisch.xlsx"');
            res.end(Buffer.from('synthetic download fixture'));return;
          }
          assert.equal(req.method,'POST');
          if(url.pathname.endsWith('/finalize.php')&&conflictOnce){
            conflictOnce=false;current.revision++;send({ok:false,code:'REVISION_CONFLICT',message:'Rooster is gewijzigd'},409);return;
          }
          assert.equal(request.body.expectedRevision,current.revision);
          if(url.pathname.endsWith('/finalize.php')){assert.equal(request.token,'finalize-fixture');current.status='published';current.revision++;}
          else if(url.pathname.endsWith('/reopen.php')){assert.equal(request.token,'reopen-fixture');current.status='concept';current.revision++;}
          else if(url.pathname.endsWith('/convert-to-custom.php')){assert.equal(request.token,'plan-fixture');current.planningMode='custom';current.revision++;}
          else throw new Error('Unexpected API: '+url.pathname);
          send({ok:true,data:{plan:current}});
        }catch(error){errors.push(String(error));send({ok:false,message:String(error)},500);}
      });
    });},
    resolveId(id){if(id==='/fixture-entry.js')return '\0fixture-entry';},
    load(id){if(id!=='\0fixture-entry')return;return `import {createApp,h} from 'vue';import {createRouter,createWebHashHistory,RouterView} from 'vue-router';
      import Detail from '/resources/js/admin/views/DashboardRosterDetailView.vue';
      import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts';import '/resources/css/admin.css';
      const Stub={render:()=>h('p',{id:'route-stub'},'Vervolgpagina')};
      const router=createRouter({history:createWebHashHistory(),routes:[{path:'/rosters/:id',name:'roster-detail',component:Detail},{path:'/rosters',name:'rosters',component:Stub},{path:'/create',name:'roster-create',component:Stub}]});
      const app=createApp({render:()=>h(RouterView)});app.use(router);app.provide(adminBootstrapKey,{rosterPlanCsrfToken:'plan-fixture',rosterFinalizeCsrfToken:'finalize-fixture',rosterReopenCsrfToken:'reopen-fixture'});app.mount('#app');`;}
  }],server:{host:'127.0.0.1',port:0,watch:null,hmr:false},logLevel:'error'});
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
try{
  await server.listen();const port=server.httpServer.address().port;
  const profile = fs.mkdtempSync(path.join(output,'chrome-'));
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
    else if(message.method==='Network.loadingFailed') browserConsole.push({type:'network',text:JSON.stringify(message.params)});
    else if(message.method==='Network.responseReceived' && message.params.response.status>=400) browserConsole.push({type:'network',text:JSON.stringify(message.params.response)});
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

  const button=label=>`[...document.querySelectorAll('button')].find(el=>el.textContent.trim()===${JSON.stringify(label)})`;
  const click=label=>mouseClick(button(label));
  const busy=label=>ready(`${button(label)}?.getAttribute('aria-busy')==='true' && ${button(label)}.disabled`);
  const status=()=>evaluate("document.querySelector('[aria-label=Roosterstatus]').textContent");
  const navigate=async id=>{
    const navigation = await cdp('Page.navigate',{url:`http://127.0.0.1:${port}/#/rosters/${id}`});
    if (navigation.errorText) throw new Error('Fixture navigation failed: ' + navigation.errorText);
    // Hash navigation keeps the old view mounted until its show.php response arrives.
    // Wait for the target plan's identity, independently of the action under test.
    await ready(`location.hash==='#/rosters/${id}' && document.querySelector('#roster-detail-title')?.textContent.includes(${JSON.stringify(plans.get(id).school.name)})`);
  };
  const assertGrouping=async()=>{
    const actual=await evaluate(`(()=>{const table=document.querySelector('.admin-roster-teacher-table');const columns=[...table.tHead.rows[0].cells].map(c=>c.querySelector('strong')?.textContent??c.textContent.trim());return [...table.tBodies[0].rows].flatMap(row=>[...row.cells].slice(1).flatMap((cell,index)=>[...cell.querySelectorAll('.admin-roster-module')].map(module=>({name:columns[index+1],time:row.cells[0].querySelector('strong').textContent+'|'+row.cells[0].querySelector('span').textContent,text:module.querySelector('strong').textContent+'\\n'+[...module.querySelectorAll('.admin-roster-group-token')].map(t=>t.textContent).join(' ')}))));})()`);
    const expected=fixture.expected.filter(item=>item.responsible!=='open').map(item=>({name:item.responsible==='school'?'School':fixture.staffCatalog.find(s=>s.id===Number(item.responsible.slice(6))).name,time:item.time,text:item.text}));
    const sort=items=>items.map(item=>JSON.stringify(item)).sort();
    assert.deepEqual(sort(actual),sort(expected),'Rendered responsible/time/module/groups do not match source.');
    assert.equal(await evaluate("[...document.querySelectorAll('.admin-roster-group-token')].every(token=>{const range=document.createRange();range.selectNodeContents(token);return getComputedStyle(token).whiteSpace==='nowrap'&&range.getClientRects().length===1;})"),true,'A group token broke across lines.');
    assert.match(await evaluate("document.querySelector('.admin-roster-teacher-table').textContent"),/OPEN[\s\S]*Klimaat Experience/);
  };
  await cdp('Runtime.enable');await cdp('Page.enable');await cdp('Debugger.enable');await cdp('Network.enable');
  await cdp('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:path.join(output,'downloads')});
  await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  await navigate(1);
  assert.equal(await status(),'Concept');
  assert.equal(await evaluate(`${button('Definitief maken')}.disabled`),true);
  assert.equal(await evaluate(`${button('Omzetten naar maatwerk')}.disabled`),true);
  assert.equal(await evaluate("document.querySelector('.admin-roster-detail__status').closest('.admin-roster-detail__action-grid')"),null);
  await assertGrouping();
  for(const width of [1440,768,375]){
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});await pause(150);
    const layout=await evaluate(`(()=>{const actions=document.querySelector('.admin-roster-detail__header-actions');const groups=[...actions.querySelectorAll('.admin-roster-detail__action-grid')];return {pageFits:document.documentElement.scrollWidth<=innerWidth,columns:groups.map(g=>getComputedStyle(g).gridTemplateColumns.split(' ').length),buttonsFit:[...actions.querySelectorAll('button')].every(b=>b.scrollWidth<=b.clientWidth+1),statusAbove:actions.querySelector('.admin-roster-detail__status').getBoundingClientRect().bottom<=groups[0].getBoundingClientRect().top};})()`);
    assert.equal(layout.pageFits,true,'Page overflow at '+width);assert.equal(layout.buttonsFit,true,'Action clipped at '+width);assert.equal(layout.statusAbove,true);
    assert.deepEqual(layout.columns,width===375?[1,1]:[2,2]);
    await evaluate("scrollTo(0,0)");await screenshot('actions-concept-'+width);
    await evaluate("document.querySelector('.admin-booking-detail__back').focus()");
    for(const label of ['Rooster controleren','Lesindeling leegmaken','Excel exporteren']){
      await key('Tab','Tab',9);
      assert.equal(await evaluate(`document.activeElement===${button(label)}`),true,'Wrong enabled-button Tab order.');
    }
  }
  await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  await click('Personeel');await ready("document.querySelector('.admin-roster-teacher-table')!==null");await assertGrouping();
  await evaluate("document.querySelector('.admin-roster-teacher-grid').scrollIntoView({block:'center'})");await screenshot('teacher-grouping-desktop');
  await click('Excel exporteren');await busy('Excel exporteren');
  await ready(`!${button('Excel exporteren')}.disabled`);
  exportError=true;await click('Excel exporteren');await ready("document.body.textContent.includes('Synthetische exportfout')");exportError=false;
  validationRevisionOverride=10;await click('Rooster controleren');await busy('Rooster controleren');
  assert.equal(await evaluate(`${button('Definitief maken')}.disabled&&${button('Beheren')}.disabled`),true);
  await ready(`!${button('Rooster controleren')}.disabled`);
  assert.equal(await evaluate(`${button('Definitief maken')}.disabled`),true,'Stale validation enabled finalization.');
  validationRevisionOverride=null;validationError=true;await click('Rooster controleren');
  await ready("document.body.textContent.includes('Je sessie is verlopen. Log opnieuw in.')");validationError=false;
  await click('Rooster controleren');await ready(`!${button('Definitief maken')}.disabled`);
  await evaluate(`${button('Rooster controleren')}.focus()`);await key('Tab','Tab',9);
  assert.equal(await evaluate(`document.activeElement===${button('Definitief maken')}`),true);
  conflictOnce=true;await click('Definitief maken');await busy('Rooster controleren');
  await ready("document.querySelector('.admin-roster-detail__revision').textContent==='Revisie 12'");
  assert.equal(await evaluate(`${button('Definitief maken')}.disabled`),true,'Changed revision retained valid result.');
  await click('Rooster controleren');await ready(`!${button('Definitief maken')}.disabled`);
  await click('Definitief maken');await ready("document.querySelector('[aria-label=Roosterstatus]').textContent==='Definitief'");
  assert.equal(await evaluate(button('Definitief maken')),undefined);
  assert.equal(await evaluate(`${button('Beheren')}.disabled`),true);
  await evaluate("scrollTo(0,0)");await screenshot('actions-definitief-desktop');
  await evaluate("window.__confirmCalls=[];window.confirm=message=>{window.__confirmCalls.push(message);return false;}");
  const beforeReopen=requests.filter(r=>r.path.endsWith('/reopen.php')).length;
  await click('Heropenen voor wijzigingen');
  assert.equal(requests.filter(r=>r.path.endsWith('/reopen.php')).length,beforeReopen);
  assert.match(await evaluate("window.__confirmCalls[0]"),/eerdere definitieve versie blijft bewaard/);
  await evaluate("window.confirm=()=>true");await click('Heropenen voor wijzigingen');await busy('Heropenen voor wijzigingen');
  await ready("document.querySelector('[aria-label=Roosterstatus]').textContent==='Concept'");
  assert.equal(await evaluate(`${button('Definitief maken')}.disabled`),true);
  await navigate(4);assert.equal(await status(),'Gecontroleerd (oude status)');
  assert.equal(await evaluate("document.querySelector('[role=group][aria-label=Rooster]')"),null);
  assert.equal(await evaluate(`${button('Beheren')}.disabled`),true);
  await navigate(2);
  assert.equal(await evaluate(`${button('Excel exporteren')}.disabled`),true);
  assert.equal(await evaluate(`${button('Omzetten naar maatwerk')}.disabled`),false);
  await click('Omzetten naar maatwerk');await busy('Omzetten naar maatwerk');
  await ready("location.hash==='#/create?plan=2' && document.querySelector('#route-stub')!==null");
  await navigate(3);
  assert.equal(await evaluate("document.querySelector('.admin-roster-detail__action-grid a').getAttribute('href')"),'#/create?plan=3');
  await navigate(5);assert.equal(await evaluate(`${button('Programma en dagindeling wijzigen')}.disabled`),true);
  if(actualPlan){
    const evidence=JSON.parse(fs.readFileSync(process.env.ROSTER_ROUND_A_EVIDENCE,'utf8'));
    await navigate(actualPlan.id);await evaluate('scrollTo(0,0)');await screenshot('actual-plan-actions');
    await click('Personeel');await ready("document.querySelector('.admin-roster-teacher-table')!==null");
    const actualCells=await evaluate(`(()=>{const table=document.querySelector('.admin-roster-teacher-table');const names=[...table.tHead.rows[0].cells].map(c=>c.querySelector('strong')?.textContent??c.textContent.trim());return [...table.tBodies[0].rows].flatMap(row=>row.querySelector('.admin-roster-grid-neutral')?[]:[...row.cells].slice(1).map((cell,index)=>({name:names[index+1],time:row.cells[0].querySelector('strong').textContent+'|'+row.cells[0].querySelector('span').textContent,text:[...cell.querySelectorAll('.admin-roster-module')].map(module=>module.querySelector('strong').textContent+'\\n'+[...module.querySelectorAll('.admin-roster-group-token')].map(t=>t.textContent).join(' ')).join('\\n\\n')})));})()`);
    const ordered=items=>items.map(item=>JSON.stringify(item)).sort();
    assert.deepEqual(ordered(actualCells),ordered(evidence.xlsxAssignments),'Actual plan: every teacher/School cell must match generated XLSX.');
    await evaluate("document.querySelector('.admin-roster-teacher-grid').scrollIntoView({block:'start'})");await screenshot('actual-plan-teachers');
    assert(!requests.some(r=>r.method==='POST'&&r.body?.planId===actualPlan.id),'Actual fixture used a mutation.');
  }
  const runtimes=await vueRuntimes();assert.equal(runtimes.length,1);
  assert.deepEqual(errors,[]);assert.deepEqual(browserConsole.filter(entry=>entry.type==='error'),[]);
  assert(requests.every(r=>r.path.startsWith('/api/admin/rosters/')));
  fs.writeFileSync(path.join(output,'checks.json'),JSON.stringify({result:'passed',widths:[1440,768,375],requests,errors,browserConsole,runtimes},null,2));
  console.log('OK: actual Vue grouping/cook/School; action grid, responsive/keyboard, lifecycle guards/loading/stale revisions, reopen confirmation, export/conversion/custom/legacy branches. Artifacts: '+output);
}catch(error){
  if(cdp){try{const state=await cdp('Runtime.evaluate',{expression:'({html:document.documentElement.outerHTML,resources:performance.getEntriesByType("resource").map(r=>r.name),url:location.href})',returnByValue:true});fs.writeFileSync(path.join(output,'failure.json'),JSON.stringify({requests,errors,browserConsole,state:state.result.value},null,2));}catch{}}
  console.error('Browser artifacts: '+output);throw error;
}finally{if(cdp){try{await cdp('Browser.close');}catch{}}socket?.close();browser?.kill();await server.close();}
