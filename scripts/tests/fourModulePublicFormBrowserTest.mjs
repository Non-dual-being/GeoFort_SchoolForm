// Real public PHP entrypoints + built Vue app + disposable MariaDB + loopback-only SMTP sink.
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import http from 'node:http';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';

assert.equal(process.env.STATUS_TEST_DB_CONFIRM,'YES_DISPOSABLE');
const root=process.cwd(), output=path.resolve('local-backups/four-module-public-review');
fs.mkdirSync(output,{recursive:true});
const php=process.env.PHP_BINARY??'C:/wamp64/bin/php/php8.3.28/php.exe';
const fixture='scripts/tests/Support/FourModulePublicFixture.php';
const run=(...args)=>{const p=spawnSync(php,['-d','xdebug.mode=off',fixture,...args],{cwd:root,env:process.env,encoding:'utf8',windowsHide:true});if(p.status!==0)throw Error(p.stderr+p.stdout);return JSON.parse(p.stdout);};
const pause=ms=>new Promise(r=>setTimeout(r,ms));
const fetch=url=>new Promise((resolve,reject)=>{http.get(url,{agent:false},res=>{const chunks=[];res.on('data',c=>chunks.push(c));res.on('end',()=>{const bytes=Buffer.concat(chunks);resolve({ok:res.statusCode>=200&&res.statusCode<300,status:res.statusCode,text:async()=>bytes.toString(),json:async()=>JSON.parse(bytes.toString()),arrayBuffer:async()=>bytes});});res.on('error',reject);}).on('error',reject);});
async function until(fn,label){const end=Date.now()+30000;while(Date.now()<end){if(await fn())return;await pause(100);}throw Error(label);}
const mails=[],errors=[],cases=[];
const smtp=net.createServer(s=>{
 s.write('220 localhost test SMTP\r\n');let buffer='',data=false,body=[],auth=0;
 s.on('data',chunk=>{buffer+=chunk;let index;while((index=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,index);buffer=buffer.slice(index+2);
  if(data){if(line==='.') {mails.push(body.join('\r\n'));fs.writeFileSync(path.join(output,`mail-${mails.length}.eml`),mails.at(-1));body=[];data=false;s.write('250 captured locally\r\n');}else body.push(line);continue;}
  if(auth){s.write(auth++===1?'334 UGFzc3dvcmQ6\r\n':'235 authenticated\r\n');if(auth===3)auth=0;continue;}
  if(/^EHLO|^HELO/.test(line))s.write('250-localhost\r\n250 AUTH LOGIN\r\n');
  else if(/^AUTH/.test(line)){auth=1;s.write('334 VXNlcm5hbWU6\r\n');}
  else if(line==='DATA'){data=true;s.write('354 send data\r\n');}
  else if(line==='QUIT')s.end('221 bye\r\n');else s.write('250 ok\r\n');
 }});
});
let database,server,browser,socket,evaluate,cdp;
let createdEnv=false;const emptyEnv='# Temporary public form test; settings supplied by the test router.\n';
try {
 if(!fs.existsSync(path.join(root,'.env'))){fs.writeFileSync(path.join(root,'.env'),emptyEnv,{flag:'wx'});createdEnv=true;}
 await new Promise(r=>smtp.listen(0,'127.0.0.1',r));
 database=run('create').database;
 const listener=net.createServer();await new Promise(r=>listener.listen(0,'127.0.0.1',r));const port=listener.address().port;await new Promise(r=>listener.close(r));
 const url=`http://127.0.0.1:${port}`;
 const envPath=path.join(output,'server-env.json');
 fs.writeFileSync(envPath,JSON.stringify({APP_ENV:'production',APP_DEBUG:'false',APP_COOLDOWN:'0',BASE_URL:'https://onderwijsboeking.test.ignorelist.com',
  DB_HOST:process.env.STATUS_TEST_DB_HOST??'127.0.0.1',DB_PORT:process.env.STATUS_TEST_DB_PORT??'3307',DB_NAME:database,DB_USER:process.env.STATUS_TEST_DB_USER??'root',DB_PASS:process.env.STATUS_TEST_DB_PASSWORD??'',
  MAIL_HOST:'127.0.0.1',MAIL_PORT:String(smtp.address().port),MAIL_ENCRYPTION:'',MAIL_PLANNER_EMAIL_USER:'planner@example.test',MAIL_PLANNER_EMAIL_PWD:'test-only',MAIL_FROM_EMAIL:'planner@example.test',MAIL_RECEIVER_DEVELOPMENT_EMAIL:'test@example.test',MAIL_CC_EMAILS:'',MAIL_BOOKING_REQUEST_BCC_EMAILS:'',MAIL_SMTP_DEBUG:'0'}));
 const log=fs.openSync(path.join(output,'server.log'),'w');
 server=spawn(php,['-d','xdebug.mode=off','-S',`127.0.0.1:${port}`,'-t','public','scripts/tests/Support/FourModulePublicRouter.php'],{cwd:root,env:{...process.env,FOUR_PUBLIC_ENV:envPath},windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
 await until(async()=>{let r;try{r=await fetch(url+'/api/getBookingProgramConfig.php');}catch{return false;}if(!r.ok)throw Error('PHP startup HTTP '+r.status+': '+await r.text());return true;},'PHP startup');
 const profile=fs.mkdtempSync(path.join(output,'chrome-'));
 browser=spawn(process.env.CHROME_PATH??'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 await until(()=>fs.existsSync(path.join(profile,'DevToolsActivePort')),'Chrome startup');
 const debug=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];
 const tabs=await(await fetch(`http://127.0.0.1:${debug}/json/list`)).json();socket=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);
 await new Promise((r,j)=>{socket.onopen=r;socket.onerror=j;});let sequence=0;const pending=new Map();
 socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description??m.params.exceptionDetails.text);};
 cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;const timer=setTimeout(()=>reject(Error(method)),15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
 evaluate=async expression=>{const r=await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description??r.exceptionDetails.text);return r.result.value;};
 await cdp('Runtime.enable');await cdp('Page.enable');
 await cdp('Network.enable');await cdp('Network.setBlockedURLs',{urls:['https://*']});
 await cdp('Page.addScriptToEvaluateOnNewDocument',{source:`window.testResponses=[];const originalFetch=window.fetch;window.fetch=async(...args)=>{const r=await originalFetch(...args);if(String(args[0]).includes('.php')){const copy=r.clone();copy.json().then(body=>window.testResponses.push({url:String(args[0]),status:r.status,body,payload:args[1]?.body instanceof FormData?Object.fromEntries(args[1].body.entries()):null})).catch(()=>{});}return r;};`});
 const click=async selector=>{await evaluate(`(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el||el.disabled)throw Error('Cannot click '+${JSON.stringify(selector)});el.click();})()`);await pause(180);};
 const textClick=async(selector,text)=>{await evaluate(`(()=>{const el=[...document.querySelectorAll(${JSON.stringify(selector)})].find(e=>e.textContent.includes(${JSON.stringify(text)}));if(!el||el.disabled)throw Error('Missing option '+${JSON.stringify(text)});el.click();})()`);await pause(180);};
 const fill=async(id,value)=>{await evaluate(`(()=>{const el=document.getElementById(${JSON.stringify(id)});if(!el||el.disabled)throw Error('Missing field '+${JSON.stringify(id)});el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));el.dispatchEvent(new Event('blur',{bubbles:true}));})()`);await pause(150);};
 const shot=async name=>fs.writeFileSync(path.join(output,name+'.png'),Buffer.from((await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
 const navigate=async variant=>{const token=String(Date.now());await cdp('Page.navigate',{url:url+'/?variant='+variant+'&testRun='+token});await until(()=>evaluate(`location.search.includes(${JSON.stringify(token)}) && (!!document.querySelector('#schoolnaam') || document.body?.textContent.includes('Ontdek ons vernieuwde schoolprogramma'))`),'Form mount');await until(()=>evaluate("window.testResponses?.some(x=>x.url.includes('getBookingProgramConfig')&&x.body.ok) && window.testResponses?.some(x=>x.url.includes('getBookingConfigValues')&&x.body.ok)"),'Config');await pause(800);};
 await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 for(const test of [
  {name:'PO vier',variant:'four',sector:'Primair onderwijs',level:'Regulier',group:'Groep 7',choice:'Klimaat Experience',students:'40',date:'2027-03-08'},
  {name:'VO vier',variant:'auto',sector:'Voortgezet onderwijs onderbouw',level:'HAVO',group:'HAVO 1',choice:'Minecraft Programmeren',students:'160',date:'2027-03-09'},
  {name:'VO vijf',variant:'five',sector:'Voortgezet onderwijs bovenbouw',level:'HAVO',group:'HAVO 4',choice:'Stop de Klimaat Klok',students:'57',date:'2027-03-11'},
 ]) {
  await navigate(test.variant);
  for(const [id,value] of Object.entries({schoolnaam:'Praktijkproef '+test.name,land:'Nederland',adres:'Testweg 1',postcode:'4171 KG',plaats:'Herwijnen',schoolTelefoonnummer:'0345123456',contactpersoonVoornaam:'Sanne',contactpersoonAchternaam:'Test',contactpersoonTelefoonnummer:'0612345678',email:'sanne@example.test'}))await fill(id,value);
  await until(()=>evaluate("!!document.querySelector('#bezoekdatum')?._flatpickr && !document.querySelector('#bezoekdatum').disabled"),'Calendar load');
  await evaluate(`document.querySelector('#bezoekdatum')._flatpickr.setDate(${JSON.stringify(test.date)},true)`);await pause(200);
  await click('button#onderwijsSector');await textClick('.education-select__option',test.sector);
  await click('#programma');await textClick('.program-select__option','Dagprogramma');
  await textClick('.education-level-card__button',test.level);await textClick('.education-group-chip',test.group);
  await textClick('.education-module-choice',test.choice);
  await fill('aantalLeerlingen',test.students);await fill('aantalBegeleiders','20');
  if(test.name==='VO vier'){
   await click('#foodAndDrinkSelection-lunch-remise_lunch');
   await evaluate(`(()=>{const el=document.querySelector('input[aria-label="Aantal remiselunches"]');el.value='180';el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('blur',{bubbles:true}));})()`);
   await until(()=>evaluate("window.testResponses.some(x=>x.url.includes('getBookingPriceQuote')&&x.body.ok&&x.body.data.foodAndDrink.amountInclVatCents>0)"),'Catering quote');
  }
  await until(()=>evaluate("window.testResponses.some(x=>x.url.includes('getBookingPriceQuote')&&x.body.ok)"),'Price quote');
  const quote=await evaluate("window.testResponses.filter(x=>x.url.includes('getBookingPriceQuote')&&x.body.ok).at(-1).body.data");
  assert.equal(quote.visit.paidSupervisors,test.variant==='five'?12:0);
  await until(()=>evaluate("window.testResponses.some(x=>x.url.includes('getBookingRoster')&&x.body.ok)"),'Example response');
  const roster=await evaluate("window.testResponses.filter(x=>x.url.includes('getBookingRoster')&&x.body.ok).at(-1).body.data");
  assert.equal(roster.available,true);assert.equal(roster.pdfUrl.includes('four-2027'),test.variant!=='five');
  const pdf=await fetch(url+roster.pdfUrl);assert.equal(pdf.status,200);assert.equal(Buffer.from(await pdf.arrayBuffer()).subarray(0,5).toString(),'%PDF-');
  await evaluate("document.querySelector('.roster-preview-field').scrollIntoView()");await shot(test.name.replaceAll(' ','-')+'-preview'); if(test.name==='PO vier'){await cdp('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await pause(300);await evaluate("document.querySelector('.roster-preview-field').scrollIntoView()");await pause(300);await shot('PO-vier-mobile-preview');await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});}
  await click('#voorwaardenAkkoord');await click('button[type=submit]');
  await until(()=>evaluate("window.testResponses.some(x=>x.url.includes('validatie.php'))"),'No submission request');
  const result=await evaluate("window.testResponses.find(x=>x.url.includes('validatie.php')).body");assert.equal(result.ok,true,JSON.stringify(result));assert.equal(result.mailDelivery,'sent');
  const payload=await evaluate("window.testResponses.find(x=>x.url.includes('validatie.php')).payload");cases.push({name:test.name,quote,roster,result,payload});console.log('PASS form: '+test.name);
 }
 const report=run('report',database);assert.equal(report.bookings.length,3);assert.equal(mails.length,3);
 for(let i=0;i<3;i++){
  assert.equal(report.bookings[i].program_version,i===2?null:'day-four-2027');
  const stored=JSON.parse(report.snapshots[i].calculation_details_json);assert.deepEqual(stored,cases[i].quote);
  assert.match(mails[i],/GeoFort_Onderwijs_Conceptrooster\.pdf/);
 }
 const submitPayload=async payload=>evaluate(`(async()=>{const body=new FormData();for(const [key,value] of Object.entries(${JSON.stringify(payload)}))body.append(key,value);const r=await fetch('/booking/validatie.php',{method:'POST',body});return {status:r.status,body:await r.json()};})()`);
 const negatives=[
  ['before-start',{bezoekdatum:'2026-12-30'},'bezoekdatum'],
  ['unknown-variant',{programVariant:'unknown'},'programma'],
  ['too-many-pupils',{aantalLeerlingen:'161'},'aantalLeerlingen'],
  ['missing-supervisors',{aantalBegeleiders:'0'},'aantalBegeleiders'],
  ['missing-terms',{voorwaardenAkkoord:'0'},'voorwaardenAkkoord'],
  ['mixed-primary-choice',{keuzemodule:'Earth-Watch',educationSelection:JSON.stringify({sector:'primairOnderwijs',selectedLevels:['regulier'],selectedGroupsByLevel:{regulier:['groep6','groep7']}})},'keuzemodule'],
 ];
 for(const [name,changes,field] of negatives){const result=await submitPayload({...cases[0].payload,...changes});assert.equal(result.status,422,name+': '+JSON.stringify(result));assert(result.body.fieldErrors[field],name+': wrong validation field');}
 for(const [date,paid] of [['2026-12-30',12],['2027-01-04',0]]){
  const payload={...cases[2].payload,programVariant:'auto',bezoekdatum:date};
  const response=await evaluate(`(async()=>{const body=new FormData();for(const [key,value] of Object.entries(${JSON.stringify(payload)}))body.append(key,value);return (await fetch('/booking/getBookingPriceQuote.php',{method:'POST',body})).json();})()`);
  assert.equal(response.ok,true,JSON.stringify(response));assert.equal(response.data.visit.paidSupervisors,paid,'Automatic date boundary');
 }
 assert.equal(run('report',database).bookings.length,3);assert.equal(mails.length,3);
 // Selecting morning from the new URL must retain the original supervisor policy and copy.
 await navigate('four');
 await until(()=>evaluate("!!document.querySelector('#bezoekdatum')?._flatpickr && !document.querySelector('#bezoekdatum').disabled"),'Morning calendar');
 await evaluate("document.querySelector('#bezoekdatum')._flatpickr.setDate('2027-03-10',true)");await pause(200);
 await click('button#onderwijsSector');await textClick('.education-select__option','Primair onderwijs');
 await click('#programma');await textClick('.program-select__option','Ochtendprogramma');
 await textClick('.education-level-card__button','Regulier');await textClick('.education-group-chip','Groep 7');
 await fill('aantalLeerlingen','40');await fill('aantalBegeleiders','20');
 await until(()=>evaluate("window.testResponses.some(x=>x.url.includes('getBookingPriceQuote')&&x.body.ok)"),'Morning quote');
 assert.equal(await evaluate("window.testResponses.filter(x=>x.url.includes('getBookingPriceQuote')&&x.body.ok).at(-1).body.data.visit.paidSupervisors"),15);
 assert.equal(await evaluate("document.body.textContent.includes('Schoolbegeleiders betalen geen entree bij het viermodulesprogramma.')"),false);
 console.log('PASS: morning keeps legacy pricing and information on the four-module URL');
 run('close',database);
 const stale=await submitPayload(cases[2].payload);assert.equal(stale.status,422);assert(stale.body.fieldErrors.programma,'Already-open five-module form must be refused');
 await navigate('five');assert.equal(await evaluate("!!document.querySelector('form')"),false);assert.equal(await evaluate("document.querySelector('a[href*=\"variant=four\"]')!==null"),true);
 await shot('five-closed');await navigate('four');assert.equal(await evaluate("!!document.querySelector('form')"),true);
 await cdp('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await shot('four-mobile');
 assert.equal(run('report',database).bookings.length,3);assert.equal(mails.length,3);
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({status:'PASS',cases,report,mails:mails.length,closedFive:true,negativeCases:negatives.map(x=>x[0]),staleFormRejected:true,errors},null,2));
 console.log('PASS: persisted program versions, exact price snapshots, three locally captured mails with PDFs, closed five-module route and mobile rendering');
} catch(e) {
 if(evaluate)fs.writeFileSync(path.join(output,'failure.json'),JSON.stringify(await evaluate("({text:document.body.innerText,inputs:[...document.querySelectorAll('input,select,button')].map(e=>({id:e.id,text:e.textContent,value:e.value,disabled:e.disabled})),responses:window.testResponses})"),null,2));
 throw e;
} finally {
 socket?.close();browser?.kill();server?.kill();await pause(300);smtp.close();if(database)run('drop',database);
 fs.rmSync(path.join(output,'server-env.json'),{force:true});
 if(createdEnv&&fs.readFileSync(path.join(root,'.env'),'utf8')===emptyEnv)fs.unlinkSync(path.join(root,'.env'));
}
