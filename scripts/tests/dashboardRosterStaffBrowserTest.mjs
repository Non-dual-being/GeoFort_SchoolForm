// Real Vue personnel flow with local synthetic HTTP. No application database or mail transport.
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync, spawn } from "node:child_process";
import { createServer } from "vite";
import vue from "@vitejs/plugin-vue";

const root = fileURLToPath(new URL("../../", import.meta.url));
const output = path.resolve(process.env.ROSTER_ROUND_C_OUTPUT ?? fs.mkdtempSync(path.join(os.tmpdir(), "geofort-staff-browser-")));
fs.mkdirSync(output, { recursive: true });
const seed = JSON.parse(fs.readFileSync(path.join(root, "database/seeds/roster_staff_v1.json"), "utf8"));
const members = seed.members.map(m => ({ id:m.id, name:m.display_name, isActive:!!m.is_active, employmentType:m.employment_type, canGuide:!!m.can_guide, canCook:!!m.can_cook, version:1, notes:null, costRates:[], preferences:m.preferences.map(p=>({moduleKey:p.module_key,moduleLabel:p.module_key.replaceAll("-"," "),rank:p.preference_rank,isLegacy:false})) }));
members[19].preferences.push({ moduleKey:"Legacy-onbekend", moduleLabel:"Legacy onbekend", rank:13, isLegacy:true });
const modules = JSON.parse(execFileSync("php",["-d","xdebug.mode=off","-r","require 'vendor/autoload.php'; echo json_encode(GeoFort\\Services\\Dashboard\\Roster\\RosterStaffConfigService::moduleOptions());"],{cwd:root,encoding:"utf8",windowsHide:true}));
let revision=1, failUpdate=false, holdDeactivation=false, releaseDeactivation; const history=new Map(); const requests=[], errors=[];
const detail=id=>({member:members.find(m=>m.id===id),catalogRevision:revision,history:history.get(id)??[]});
const server=await createServer({root,configFile:false,envDir:false,publicDir:false,cacheDir:path.join(output,"vite"),optimizeDeps:{entries:[],include:["vue","vue-router","lucide-vue-next"]},plugins:[vue(),{
  name:"personnel-fixture",
  configureServer(server){server.middlewares.use((req,res,next)=>{
    const url=new URL(req.url,"http://127.0.0.1");
    if(url.pathname==="/"){res.setHeader("Content-Type","text/html");res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/fixture-entry.js"></script></body></html>');return;}
    if(!url.pathname.startsWith("/api/")){next();return;}
    let body="";req.on("data",chunk=>body+=chunk);req.on("end",async()=>{
      let payload=body?JSON.parse(body):null; const op=path.basename(url.pathname,".php");
      requests.push({method:req.method,op,body:payload,token:req.headers["x-csrf-token"]});
      const json=(data,status=200)=>{res.statusCode=status;res.setHeader("Content-Type","application/json");res.end(JSON.stringify(data));};
      if(op==="index"){json({ok:true,data:{items:members,catalogRevision:revision,modules}});return;}
      if(op==="show"){json({ok:true,data:detail(Number(url.searchParams.get("staffId")))});return;}
      const expected={"create":"create-csrf","update":"update-csrf","preview-impact":"impact-csrf","deactivate":"deactivate-csrf"}[op];
      if(req.method!=="POST"||req.headers["x-csrf-token"]!==expected){errors.push("Invalid mutation auth scope");json({ok:false},403);return;}
      if(op==="preview-impact"){
        const impact=!payload.isActive?[{planId:42,visitDate:"2028-10-10",revision:7,reasons:["Medewerker wordt inactief maar staat nog in een conceptrooster."]}]:[];
        json({ok:true,data:{impact,impactToken:"fixture-signed-"+revision,expiresInSeconds:300,catalogRevision:revision,version:payload.expectedVersion}});return;
      }
      if(op==="deactivate"&&holdDeactivation)await new Promise(resolve=>{releaseDeactivation=resolve;});
      if((op==="update"||op==="deactivate")&&failUpdate){json({ok:false,code:"STALE_STAFF_IMPACT",message:"De impactcontrole is verouderd. Je invoer is behouden."},409);return;}
      if((op==="update"||op==="deactivate")&&(payload.impactToken!=="fixture-signed-"+revision||payload.expectedCatalogRevision!==revision)){json({ok:false,message:"Verouderde versie"},409);return;}
      const existing=op==="create"?null:members.find(m=>m.id===payload.staffId);
      if(op==="deactivate"){
        assert.deepEqual(Object.keys(payload).sort(),["expectedCatalogRevision","expectedVersion","impactToken","staffId"]);
        payload={...existing,...payload,isActive:false,preferences:existing.preferences.filter(p=>!p.isLegacy)};
      }
      const member={id:existing?.id??21,version:(existing?.version??0)+1,name:payload.name,isActive:payload.isActive,employmentType:payload.employmentType,canGuide:payload.canGuide,canCook:payload.canCook,notes:null,costRates:[],
        preferences:[...payload.preferences.map(p=>({...p,moduleLabel:modules.find(m=>m.moduleKey===p.moduleKey)?.moduleLabel??p.moduleKey,isLegacy:false})),...(existing?.preferences.filter(p=>p.isLegacy&&!payload.removeLegacyModuleKeys?.includes(p.moduleKey)).map(p=>({...p,rank:payload.legacyPreferenceRanks?.find(v=>v.moduleKey===p.moduleKey)?.rank??p.rank}))??[])]};
      if(existing)members[members.indexOf(existing)]=member;else members.push(member);
      revision++;
      history.set(member.id,[{id:revision,actorId:1,action:existing?"updated":"created",previousVersion:member.version-1,version:member.version,before:existing,after:member,impact:[],createdAt:"2026-10-06 00:00:00"},...(history.get(member.id)??[])]);
      json({ok:true,data:detail(member.id)},op==="create"?201:200);
    });
  });},
  resolveId(id){if(id==="/fixture-entry.js")return "\0staff-fixture";},
  load(id){if(id!=="\0staff-fixture")return;return [
    "import {createApp} from 'vue'; import {createRouter,createWebHashHistory} from 'vue-router';",
    "import Staff from '/resources/js/admin/views/DashboardRosterStaffView.vue';",
    "import {adminBootstrapKey} from '/resources/js/admin/types/admin.ts'; import '/resources/css/admin.css';",
    "const app=createApp(Staff);app.use(createRouter({history:createWebHashHistory(),routes:[{path:'/',name:'rosters',component:{render:()=>null}}]}));",
    "app.provide(adminBootstrapKey,{rosterStaffCreateCsrfToken:'create-csrf',rosterStaffUpdateCsrfToken:'update-csrf',rosterStaffImpactCsrfToken:'impact-csrf',rosterStaffDeactivateCsrfToken:'deactivate-csrf'});app.mount('#app');",
  ].join("\n");}
}],server:{host:"127.0.0.1",port:0,watch:null,hmr:false},logLevel:"error"});
let browser,socket,inspect;
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function until(fn,message){const end=Date.now()+20000;while(Date.now()<end){if(await fn())return;await wait(80);}throw new Error(message);}
try{
  await server.listen();const profile=fs.mkdtempSync(path.join(output,"chrome-"));
  browser=spawn(process.env.CHROME_PATH??"C:/Program Files/Google/Chrome/Application/chrome.exe",["--headless=new","--disable-gpu","--no-first-run","--no-default-browser-check","--remote-debugging-port=0","--user-data-dir="+profile,"about:blank"],{windowsHide:true,stdio:"ignore"});
  await until(()=>fs.existsSync(path.join(profile,"DevToolsActivePort")),"Chrome start");
  const port=fs.readFileSync(path.join(profile,"DevToolsActivePort"),"utf8").split("\n")[0];
  const tabs=await(await fetch("http://127.0.0.1:"+port+"/json/list")).json();
  socket=new WebSocket(tabs.find(t=>t.type==="page").webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
  const pending=new Map();let sequence=0;
  socket.onmessage=event=>{const data=JSON.parse(event.data);if(data.id){const task=pending.get(data.id);if(!task)return;clearTimeout(task.timer);pending.delete(data.id);data.error?task.reject(data.error):task.resolve(data.result);}else if(data.method==="Runtime.exceptionThrown")errors.push(data.params.exceptionDetails.exception?.description??data.params.exceptionDetails.text);};
  const cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;const timer=setTimeout(()=>reject(new Error("CDP timeout "+method)),15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const evaluate=async expression=>{const r=await cdp("Runtime.evaluate",{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
  inspect=evaluate;
  const ready=expression=>until(()=>evaluate(expression),"Browser condition: "+expression);
  const button=label=>"[...document.querySelectorAll('button')].find(el=>el.textContent.trim()==="+JSON.stringify(label)+")";
  const click=async label=>{await ready("!!"+button(label));await evaluate(button(label)+".click()");};
  const fill=(selector,value,event="input")=>evaluate("(()=>{const el=document.querySelector("+JSON.stringify(selector)+");el.value="+JSON.stringify(value)+";el.dispatchEvent(new Event("+JSON.stringify(event)+",{bubbles:true}));})()");
  const shot=async name=>{const r=await cdp("Page.captureScreenshot",{format:"png"});fs.writeFileSync(path.join(output,name+".png"),Buffer.from(r.data,"base64"));};
  await cdp("Runtime.enable");await cdp("Page.enable");
  await cdp("Emulation.setDeviceMetricsOverride",{width:1360,height:1000,deviceScaleFactor:1,mobile:false});
  await cdp("Page.navigate",{url:"http://127.0.0.1:"+server.httpServer.address().port+"/"});
  await ready("document.querySelectorAll('.admin-roster-staff-config__member').length===20");
  await ready("!!document.querySelector('.admin-roster-staff-config__module-window[style]')");
  const layoutEvidence=[];
  for(const width of [1600,1280,768,390,375]){
    await cdp("Emulation.setDeviceMetricsOverride",{width,height:1000,deviceScaleFactor:1,mobile:width<500});await wait(180);
    const measurements=await evaluate(`(()=>{
      const cards=[...document.querySelectorAll('.admin-roster-staff-config__member')];
      return {width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,
        gap:cards[0].getBoundingClientRect().top-document.querySelector('.admin-roster-staff-config__toolbar').getBoundingClientRect().bottom,
        cards:cards.map(card=>{const box=card.getBoundingClientRect(), viewport=card.querySelector('.admin-roster-staff-config__module-window'), button=card.querySelector('footer button').getBoundingClientRect();
          const region=viewport?.getBoundingClientRect(),rows=[...card.querySelectorAll('li')];
          return {top:box.top,bottom:box.bottom,buttonBottom:button.bottom,count:rows.length,scroll:viewport?viewport.scrollHeight>viewport.clientHeight+1:false,
          visible:region?rows.filter(row=>row.getBoundingClientRect().top<region.bottom-.5&&row.getBoundingClientRect().bottom>region.top+.5).length:0};})};})()`);
    assert.equal(measurements.overflow,false,`No horizontal clipping ${width}`);
    assert.ok(measurements.gap>=24,"Toolbar clearly separated from cards");
    for(const card of measurements.cards){assert.equal(card.visible,Math.min(5,card.count),`Exactly five visible max at ${width}`);assert.equal(card.scroll,card.count>5,"Only 6+ skills scroll");}
    for(const card of measurements.cards){for(const peer of measurements.cards.filter(p=>Math.abs(p.top-card.top)<1)){assert.ok(Math.abs(card.buttonBottom-peer.buttonBottom)<1,"Bottom actions align in grid row");}}
    layoutEvidence.push(measurements);await shot(`catalog-${width}`);
  }
  const scrollEvidence=await evaluate(`(()=>{const region=document.querySelector('.is-scrollable'),card=region.closest('article'),before=[card.querySelector('header').getBoundingClientRect().top,card.querySelector('footer').getBoundingClientRect().top];region.scrollTop=region.scrollHeight;return {before,after:[card.querySelector('header').getBoundingClientRect().top,card.querySelector('footer').getBoundingClientRect().top],scroll:region.scrollTop};})()`);
  assert.deepEqual(scrollEvidence.before,scrollEvidence.after,"Header and footer never scroll with modules");assert.ok(scrollEvidence.scroll>0);
  await cdp("Emulation.setDeviceMetricsOverride",{width:1280,height:1000,deviceScaleFactor:1,mobile:false});
  const requestsBeforeSearch=requests.length;
  const query=members[0].name.slice(0,4).toUpperCase();await fill('#staff-search',query);await wait(80);
  const expectedMatches=members.filter(m=>m.name.toLowerCase().includes(query.toLowerCase())).length;
  assert.equal(await evaluate("document.querySelectorAll('.admin-roster-staff-config__member').length"),expectedMatches);
  assert.match(await evaluate("document.querySelector('#staff-result-count').textContent"),new RegExp(`${expectedMatches} van 20`));
  await fill('#staff-search','niemand-123');await ready("document.querySelector('.admin-bookings__state')?.textContent.includes('Geen medewerkers gevonden')");
  await evaluate("document.querySelector('[aria-label=\"Zoekterm wissen\"]').click()");await ready("document.querySelectorAll('.admin-roster-staff-config__member').length===20");
  assert.equal(await evaluate("document.activeElement.id"),'staff-search');assert.equal(requests.length,requestsBeforeSearch,"No server requests for search/clear");
  const key=async(key,code,windowsVirtualKeyCode)=>{await cdp('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode});await cdp('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode});};
  await key('Tab','Tab',9);
  assert.equal(await evaluate('document.activeElement.id'),`staff-edit-${members[0].id}`,"Search tab order continues to first card action");
  const beforeKeyboardScroll=await evaluate(`(()=>{const region=document.querySelector('.is-scrollable');region.focus();region.scrollTop=0;return region.closest('article').querySelector('footer').getBoundingClientRect().top;})()`);
  assert.ok(await evaluate("parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2"),"Scrollable region has visible keyboard focus");
  await key('End','End',35);await ready("document.activeElement.scrollTop>0");
  assert.equal(await evaluate("document.activeElement.closest('article').querySelector('footer').getBoundingClientRect().top"),beforeKeyboardScroll,"Keyboard scroll leaves card action stationary");
  await evaluate("document.querySelector('#staff-search').focus();window.scrollTo(0,0)");
  await shot("catalog-desktop");

  await click("Medewerker toevoegen");await ready("!!document.querySelector('[name=staffName]')");
  await fill("[name=staffName]","Browser medewerker <b>tekst</b>");
  await fill("[name=employmentType]","volunteer","change");
  await evaluate("document.querySelector('[name=canCook]').click()");
  const keys=modules.slice(0,6).map(m=>m.moduleKey);
  const rankSelector=key=>'[aria-label="Voorkeursrang '+modules.find(m=>m.moduleKey===key).moduleLabel+'"]';
  const climateKey='Minecraft-Klimaatspeurtocht', programmingKey='Minecraft-Programmeren';
  const rankGeometry=key=>evaluate(`(()=>{const input=document.querySelector(${JSON.stringify(rankSelector(key))}),wrapper=input.closest('.admin-roster-staff-editor__rank'),s=getComputedStyle(input),r=input.getBoundingClientRect();return {width:r.width,height:r.height,paddingLeft:s.paddingLeft,paddingRight:s.paddingRight,relativeLeft:r.left-wrapper.getBoundingClientRect().left,relativeTop:r.top-input.closest('.admin-roster-staff-editor__skill').getBoundingClientRect().top,disabled:input.disabled,value:input.value,cursor:getComputedStyle(wrapper).cursor,description:document.getElementById(input.getAttribute('aria-describedby'))?.textContent};})()`);
  const disabledGeometry=await rankGeometry(climateKey);
  assert.equal(disabledGeometry.disabled,true);assert.equal(disabledGeometry.value,'');assert.equal(disabledGeometry.cursor,'help');
  assert.equal(disabledGeometry.description,'Vink deze module aan om een voorkeursrang in te stellen.');
  await evaluate(`document.querySelector('[name="skill-${climateKey}"]').focus()`);
  await key('Tab','Tab',9);
  assert.equal(await evaluate('document.activeElement.matches("input:disabled")'),false,'Disabled rank input skipped by Tab');
  await evaluate(`document.querySelector('[name="skill-${climateKey}"]').click()`);
  const enabledGeometry=await rankGeometry(climateKey);
  for(const property of ['width','height','paddingLeft','paddingRight','relativeLeft','relativeTop'])assert.equal(enabledGeometry[property],disabledGeometry[property],`Enabled/disabled identical ${property}`);
  assert.equal(await evaluate(`document.querySelector('[name="skill-${programmingKey}"]').checked`),false,'Climate selection never selects programming');
  await evaluate(`document.querySelector('[name="skill-${climateKey}"]').click();document.querySelector('[name="skill-${programmingKey}"]').click()`);
  assert.equal(await evaluate(`document.querySelector('[name="skill-${climateKey}"]').checked`),false,'Programming selection never selects climate');
  await evaluate(`document.querySelector('[name="skill-${programmingKey}"]').click()`);
  for(const key of keys)await evaluate("document.querySelector('[name=skill-"+key+"]').click()");
  assert.equal(await evaluate("document.querySelector("+JSON.stringify(rankSelector(keys[5]))+").value"),'6',"1..5 adds rank6");
  await evaluate("document.querySelector('[name=skill-"+keys[5]+"]').click();document.querySelector('[name=skill-"+keys[2]+"]').click();document.querySelector('[name=skill-"+keys[5]+"]').click()");
  assert.equal(await evaluate("document.querySelector("+JSON.stringify(rankSelector(keys[5]))+").value"),'6',"1,2,4,5 adds rank6");
  await fill(rankSelector(keys[1]),'1');await ready("!!document.querySelector('[aria-invalid=true]')");
  const beforeInvalid=requests.length;await click("Medewerker toevoegen");await wait(80);
  assert.equal(requests.length,beforeInvalid,"Frontend duplicates block submit");
  await shot("rank-error-desktop");await fill(rankSelector(keys[1]),'2');
  await fill(rankSelector(keys[5]),'255');await evaluate("document.querySelector('[name=skill-"+keys[2]+"]').click()");
  await ready("document.querySelector('[role=alert]')?.textContent.includes('hoogste rang is 255')");
  assert.equal(await evaluate("document.querySelector('[name=skill-"+keys[2]+" ]').checked"),false,"Max255 cannot silently normalize");
  await fill(rankSelector(keys[5]),'6');
  for(const width of [1280,768,390,375]){
    await cdp("Emulation.setDeviceMetricsOverride",{width,height:1000,deviceScaleFactor:1,mobile:width<500});await wait(100);
    assert.equal(await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),true,`Editor ${width} no clipping`);
    const columns=await evaluate("getComputedStyle(document.querySelector('.admin-roster-staff-editor__skills')).gridTemplateColumns.split(' ').length");
    assert.equal(columns,width>900?2:1,`Editor responsive grid ${width}`);
    assert.ok(await evaluate("[...document.querySelectorAll('.admin-text-input')].every(el=>parseFloat(getComputedStyle(el).paddingLeft)>=12&&parseFloat(getComputedStyle(el).paddingRight)>=12)"),"Input inner spacing");
    const inputs=await evaluate(`(()=>{return [...document.querySelectorAll('.admin-roster-staff-editor__skills:not(.admin-roster-staff-editor__legacy *) .admin-roster-staff-editor__rank input')].map(el=>({disabled:el.disabled,width:el.getBoundingClientRect().width,top:el.getBoundingClientRect().top,cardTop:el.closest('.admin-roster-staff-editor__skill').getBoundingClientRect().top,paddingLeft:getComputedStyle(el).paddingLeft,paddingRight:getComputedStyle(el).paddingRight,cursor:getComputedStyle(el).cursor}));})()`);
    assert.equal(inputs.length,modules.length,'Every module keeps full rank layout');
    assert.ok(inputs.some(i=>i.disabled)&&inputs.some(i=>!i.disabled));
    assert.ok(inputs.every(i=>Math.abs(i.width-inputs[0].width)<1&&i.paddingLeft===inputs[0].paddingLeft&&i.paddingRight===inputs[0].paddingRight),`Identical rank width/padding at ${width}`);
    for(const input of inputs)for(const peer of inputs.filter(i=>Math.abs(i.cardTop-input.cardTop)<1))assert.ok(Math.abs(input.top-peer.top)<1,`Rank inputs align vertically within each grid row at ${width}`);
    assert.ok(inputs.filter(i=>i.disabled).every(i=>i.cursor==='help'),'Disabled rank inherits help cursor');
    await evaluate("document.querySelector('.admin-roster-staff-editor__skills').scrollIntoView()");await shot(`editor-${width}`);
  }
  await click("Medewerker toevoegen");await ready("document.querySelectorAll('.admin-roster-staff-config__member').length===21");
  assert.equal(members.at(-1).employmentType,"volunteer");assert.equal(members.at(-1).canCook,true);assert.deepEqual(members.at(-1).preferences.map(p=>p.rank),[1,2,4,5,6]);
  assert.equal(await evaluate("document.activeElement.id"),'staff-edit-21',"New member focused without page reload");
  assert.equal(await evaluate("document.querySelector('.admin-roster-staff-config').querySelectorAll('h2 b').length"),0,"Escaped dynamic name");
  // A normal edit must preserve the seed's legacy duplicate ranks exactly.
  const tied=members.find(m=>new Set(m.preferences.map(p=>p.rank)).size<m.preferences.length);const tiedBefore=structuredClone(tied.preferences);
  await evaluate(`document.getElementById('staff-edit-${tied.id}').click()`);await ready("!!document.querySelector('[name=staffName]')");
  await fill('[name=staffName]',tied.name+' hernoemd');await click('Wijziging controleren');await ready("!document.querySelector('#staff-editor-title')");
  assert.deepEqual(members.find(m=>m.id===tied.id).preferences,tiedBefore,"Basic edit preserves all legacy ties");
  await cdp("Emulation.setDeviceMetricsOverride",{width:1280,height:1000,deviceScaleFactor:1,mobile:false});

  await evaluate("document.querySelector('[aria-label=\"Wijzig Nelleke de With\"]').click()");await ready("!!document.querySelector('.admin-roster-staff-editor__legacy')");
  await fill("[name=staffName]","Kok hernoemd in browser");
  await click("Wijziging controleren");await ready("!document.querySelector('#staff-editor-title')");
  assert.equal(members.find(m=>m.id===20).preferences.at(-1).moduleKey,"Legacy-onbekend","Name edit retains legacy");
  await evaluate("document.querySelector('[aria-label=\"Wijzig Kok hernoemd in browser\"]').click()");await ready("!!document.querySelector('[name=isActive]')");
  const writesBefore=requests.filter(r=>r.op==="deactivate").length;
  await click("Medewerker deactiveren");await ready("!!document.querySelector('dialog[open]')");
  assert.equal(requests.filter(r=>r.op==="deactivate").length,writesBefore,"No deactivation before confirmation");
  assert.equal(await evaluate("document.activeElement.textContent.trim()"),'Annuleren',"Safe default focus");
  assert.match(await evaluate("document.querySelector('dialog').textContent"),/nieuwe planning.*roosterhistorie.*opnieuw activeren/s);
  await cdp("Input.dispatchKeyEvent",{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await cdp("Input.dispatchKeyEvent",{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  await ready("!document.querySelector('dialog[open]')");assert.equal(await evaluate("document.activeElement.textContent.trim()"),'Medewerker deactiveren');
  await click("Medewerker deactiveren");await ready("!!document.querySelector('dialog[open]')");
  assert.match(await evaluate("document.querySelector('.admin-roster-staff-editor__impact').textContent"),/42.*2028-10-10/s);
  await shot("impact-desktop");
  const confirmDeactivate=()=>evaluate("document.querySelector('dialog .admin-button--danger').click()");
  failUpdate=true;await confirmDeactivate();await ready("document.querySelector('[role=alert]')?.textContent.includes('verouderd')");
  assert.equal(await evaluate("document.querySelector('[name=staffName]').value"),"Kok hernoemd in browser");
  assert.equal(await evaluate("document.querySelector('[name=isActive]').checked"),true);
  assert.equal(members.find(m=>m.id===20).isActive,true,"Conflict did not save");
  await shot("conflict-desktop");
  failUpdate=false;await click("Medewerker deactiveren");await ready("!!document.querySelector('dialog[open]')");
  await cdp("Emulation.setDeviceMetricsOverride",{width:375,height:844,deviceScaleFactor:1,mobile:true});await shot("deactivate-mobile");
  assert.equal(await evaluate("document.querySelector('dialog').getBoundingClientRect().right<=innerWidth+1"),true,"Mobile dialog fits");
  holdDeactivation=true;await confirmDeactivate();
  await ready("document.querySelector('dialog .admin-button--danger')?.disabled");
  assert.equal(await evaluate("[...document.querySelectorAll('dialog button')].every(b=>b.disabled)"),true,"All confirmation actions disabled during mutation");
  await cdp("Input.dispatchKeyEvent",{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await cdp("Input.dispatchKeyEvent",{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  assert.equal(await evaluate("!!document.querySelector('dialog[open]')"),true,"Escape cannot dismiss a pending mutation");
  holdDeactivation=false;releaseDeactivation();
  await ready("!document.querySelector('#staff-editor-title')");assert.equal(members.find(m=>m.id===20).isActive,false);
  await fill('#staff-search','KOK HERNOEMD');await ready("document.querySelectorAll('.admin-roster-staff-config__member').length===1");
  assert.match(await evaluate("document.querySelector('article').textContent"),/Inactief/);await evaluate("document.querySelector('[aria-label=\"Zoekterm wissen\"]').click()");

  await evaluate("document.querySelector('[aria-label=\"Wijzig Kok hernoemd in browser\"]').click()");await ready("!!document.querySelector('[name=isActive]')");
  await evaluate("document.querySelector('[name=isActive]').click()");await click("Wijziging controleren");await ready("!document.querySelector('#staff-editor-title')");
  assert.equal(members.find(m=>m.id===20).isActive,true,"Reactivate");
  await evaluate("document.querySelector('[aria-label=\"Wijzig Kok hernoemd in browser\"]').click()");await ready("!!document.querySelector('.admin-roster-staff-editor__legacy input')");
  await evaluate("document.querySelector('.admin-roster-staff-editor__legacy input').click()");
  await click("Wijziging controleren");await ready("!!document.querySelector('#staff-impact-title')");
  assert.equal(members.find(m=>m.id===20).preferences.length,1,"Explicit legacy removal awaits confirmation");
  await click("Gevolgen bevestigen en opslaan");await ready("!document.querySelector('#staff-editor-title')");
  assert.equal(members.find(m=>m.id===20).preferences.length,0,"Explicit legacy removal persisted");
  await evaluate("document.querySelector('[aria-label=\"Wijzig Kok hernoemd in browser\"]').click()");await ready("!!document.querySelector('.admin-roster-staff-history')");
  await evaluate("document.querySelector('.admin-roster-staff-history').open=true");
  assert.match(await evaluate("document.querySelector('.admin-roster-staff-history').textContent"),/Weergavenaam.*Nelleke de With.*Kok hernoemd/s);
  await cdp("Emulation.setDeviceMetricsOverride",{width:390,height:844,deviceScaleFactor:1,mobile:true});
  await evaluate("window.scrollTo(0,0)");await shot("editor-mobile");
  assert.equal(await evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"),true,"No mobile horizontal clipping");
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(output,"browser-results.json"),JSON.stringify({ok:true,requests,errors,layoutEvidence,checks:["create","roles","max+1 gaps and max255","duplicate ranks blocked","search counts/clear no requests","exactly5 modules scroll","aligned bottom actions","escaped name","legacy ties preserved","impact before write","409 retains input","separate deactivation scope","safe cancel/Escape focus","deactivate/reactivate","explicit legacy removal","audit","1600/1280/768/390/375 layouts"]},null,2));
  console.log("PASS personnel browser flow; screenshots: "+output);
}catch(error){
  if(inspect)try{fs.writeFileSync(path.join(output,"failure.txt"),String(await inspect("document.body.innerText")));}catch{}
  throw error;
}finally{releaseDeactivation?.();socket?.close();browser?.kill();await server.close();}
