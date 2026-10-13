// Actual Vue wizard, using the output of FourModuleRosterIntegrationTest as HTTP fixtures.
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
const root=process.cwd();
const output=path.resolve(process.env.FOUR_MODULE_OUTPUT ?? 'local-backups/four-module-review/exports');
const fixture=JSON.parse(fs.readFileSync(path.join(output,'wizard-3.json'),'utf8'));
const errors=[];let calls=0;
const server=await createServer({root,configFile:false,envDir:false,publicDir:false,cacheDir:path.join(output,'vite'),logLevel:'error',optimizeDeps:{entries:[],include:['vue','lucide-vue-next']},
 plugins:[vue(),{name:'four-module-fixture',configureServer(server){server.middlewares.use((req,res,next)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end('<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="admin-page"><main id="app" class="admin-shell admin-main"></main><script type="module" src="/four-fixture.js"></script></body></html>');return;}
  if(!req.url.startsWith('/api/'))return next();
  let body='';req.on('data',c=>body+=c);req.on('end',()=>{try{
   assert.equal(req.url,'/api/admin/rosters/generation-preview.php');assert.equal(req.headers['x-csrf-token'],'test-token');
   const data=JSON.parse(body);assert.equal(data.rounds.length,4);assert.equal(data.rounds[3].endTime,'13:45');calls++;
   res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,data:fixture.preview}));
  }catch(e){errors.push(String(e));res.statusCode=500;res.end('{}');}});
 });},resolveId(id){if(id==='/four-fixture.js')return '\0four-fixture';},load(id){if(id==='\0four-fixture')return `import{createApp,h}from'vue';import Wizard from '/resources/js/admin/components/rosters/RosterAutoGeneratorPanel.vue';import{adminBootstrapKey}from'/resources/js/admin/types/admin.ts';import'/resources/css/admin.css';const app=createApp({render:()=>h(Wizard,{plan:${JSON.stringify(fixture.plan)}})});app.provide(adminBootstrapKey,{rosterGenerationCsrfToken:'test-token'});app.mount('#app');`;}}],server:{host:'127.0.0.1',port:0,watch:null,hmr:false}});
let browser,socket;
const pause=ms=>new Promise(r=>setTimeout(r,ms));
async function until(fn,label){const end=Date.now()+30000;while(Date.now()<end){if(await fn())return;await pause(100);}throw Error(label);}
try{
 await server.listen();const port=server.httpServer.address().port;
 const profile=fs.mkdtempSync(path.join(output,'chrome-four-'));
 browser=spawn(process.env.CHROME_PATH??'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 await until(()=>fs.existsSync(path.join(profile,'DevToolsActivePort')),'Chrome startup');
 const debug=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];
 const tabs=await(await fetch(`http://127.0.0.1:${debug}/json/list`)).json();
 socket=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);
 await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
 const pending=new Map();let sequence=0;
 socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description??m.params.exceptionDetails.text);};
 const cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;const timer=setTimeout(()=>reject(Error(method)),10000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
 const evaluate=async expression=>(await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result.value;
 const click=async text=>{await evaluate(`[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===${JSON.stringify(text)}).click()`);await pause(150);};
 const shot=async name=>fs.writeFileSync(path.join(output,name+'.png'),Buffer.from((await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
 await cdp('Runtime.enable');await cdp('Page.enable');await cdp('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await cdp('Page.navigate',{url:`http://127.0.0.1:${port}/`});
 await until(()=>evaluate("document.body.textContent.includes('Stap 1 van 5')"),'Wizard did not mount');
 await click('Volgende');
 assert.equal(await evaluate("document.querySelectorAll('.admin-roster-day-template__row--activity').length"),4);
 assert.equal(await evaluate("document.body.textContent.includes('11:45') && document.body.textContent.includes('12:15') && document.body.textContent.includes('14:00')"),true);
 await shot('wizard-four-timeline');
 await click('Volgende');assert.equal(await evaluate("document.querySelectorAll('.admin-roster-setup-module').length"),4);
 await click('Volgende');
 await shot('wizard-four-staff');
 await click('Volgende');
 // Personnel uses an intermediate preferences screen before step 5.
 if(!await evaluate("document.body.textContent.includes('Automatisch voorstel maken')"))await click('Volgende');
 await click('Automatisch voorstel maken');await until(()=>evaluate("!!document.querySelector('.admin-roster-generator-preview__table')"),'Preview missing');
 assert.equal(calls,1);assert.deepEqual(errors,[]);
 await shot('wizard-four-preview');
 await cdp('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await pause(200);await shot('wizard-four-mobile');
 fs.writeFileSync(path.join(output,'browser-results.json'),JSON.stringify({status:'PASS',rounds:4,modules:4,desktop:true,mobile:true,previewRequests:calls},null,2));
 console.log('PASS: real Vue wizard, four rounds, lunch, modules, staffing, preview payload, desktop/mobile, no runtime errors');
}finally{socket?.close();browser?.kill();await server.close();}
