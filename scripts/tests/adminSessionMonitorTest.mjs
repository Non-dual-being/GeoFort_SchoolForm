import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';

const source = fs.readFileSync(new URL('../../resources/js/admin/services/adminSessionMonitor.ts', import.meta.url), 'utf8');
const js = ts.transpileModule(source, {compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText;
const {createSessionMonitor} = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'));

function fixture(timeoutSeconds = 1800) {
  const state = {now:0, lastActivity:0, online:true, authenticated:true, failStatus:0, requests:[], expired:[], warnings:[]};
  const options = {
    timeoutSeconds, now:()=>state.now,
    expired: server => state.expired.push(server), warning:show=>state.warnings.push(show),
    request: async (active, token) => {
      state.requests.push({active,token,time:state.now});
      if (!state.online) throw Error('Offline');
      if (state.failStatus) return {status:state.failStatus};
      if (!state.authenticated || state.now-state.lastActivity >= timeoutSeconds*1000) {
        state.authenticated=false; return {status:401};
      }
      if (active) { assert.equal(token, 'activity-token'); state.lastActivity=state.now; }
      return {status:200,remainingSeconds:(state.lastActivity+timeoutSeconds*1000-state.now)/1000,csrfToken:'activity-token'};
    },
  };
  return {state,options,monitor:createSessionMonitor(options)};
}
{
  const {state:s,monitor:m}=fixture();
  await m.tick(true);
  for(let i=1;i<=1800;i++){s.now=i*1000;await m.tick();}
  assert.deepEqual(s.expired,[true]); assert.equal(s.lastActivity,0);
  assert(s.requests.every(r=>!r.active),'Passive checks must never renew');
  assert(s.requests.length<100,'Status polling should be bounded');
  assert(s.warnings.includes(true),'Idle warning absent');
  const count=s.requests.length;await m.activity(true);await m.tick(true);assert.equal(s.requests.length,count);
}
{
  const {state:s,monitor:m}=fixture();await m.tick(true);
  for(let i=1;i<=2400;i++){s.now=i*1000;await m.activity();await m.tick();}
  assert.deepEqual(s.expired,[]);assert(s.requests.filter(r=>r.active).length<=120,'Activity not throttled');
  assert.equal(s.lastActivity,2400000);
  s.now=4200000;await m.tick();assert.deepEqual(s.expired,[true]);
}
{
  const {state:s,monitor:m}=fixture();await m.tick(true);
  s.now=1700000;await m.activity(true);assert.equal(s.lastActivity,1700000);
  s.now=1800000;await m.tick(true);assert.deepEqual(s.expired,[]);
  s.now=3500000;await m.activity(true);assert.deepEqual(s.expired,[true]);
  assert.equal(s.requests.at(-1).active,false,'Wake from sleep revived expired session');
}
{
  const {state:s,options,monitor:first}=fixture(); const second=createSessionMonitor(options);
  await first.tick(true);await second.tick(true);
  s.now=1700000;await second.activity(true);
  s.now=1800000;await first.tick(true);assert.deepEqual(s.expired,[],'Idle tab logged out active tab');
  s.authenticated=false;s.now+=1000;await first.tick(true);assert.deepEqual(s.expired,[true]);
}
for(const failure of ['offline','forbidden']) {
  const {state:s,monitor:m}=fixture();await m.tick(true);
  if(failure==='offline')s.online=false;else s.failStatus=403;
  s.now=1700000;await m.activity(true);assert.equal(s.lastActivity,0);
  s.now=1800000;await m.tick(true);assert.deepEqual(s.expired,[false]);
}
{
  const {state:s,options}=fixture();let resolve;
  const monitor=createSessionMonitor({...options,request:(active,token)=>new Promise(r=>{resolve=()=>options.request(active,token).then(r);})});
  const pending=monitor.tick(true);s.now=1000;await monitor.activity();
  assert.equal(s.requests.length,0,'Parallel request escaped pending guard');await resolve();await pending;
  s.now=21000;const renewed=monitor.tick();await resolve();await renewed;
  assert.equal(s.lastActivity,21000,'Interaction during status request was lost');
}
{
  const {state:s,monitor:m}=fixture();s.authenticated=false;await m.tick(true);assert.deepEqual(s.expired,[true]);
}
console.log('PASS: idle expiry, active typing, request throttling, warning, sleep, other active tab, logout, failed/offline renewal and in-flight interaction');
