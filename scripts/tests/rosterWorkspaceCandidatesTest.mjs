import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),ts=require('typescript');
const source=fs.readFileSync(new URL('../../resources/js/admin/services/rosterWorkspaceCandidates.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText;
const {jointAssignmentIsCurrent:current}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const state=[{id:1,sessions:[{id:11,start:'12:15',end:'13:00',cook:3,groups:[{id:21,kind:'staff',staffIds:[1]},{id:22,kind:'staff',staffIds:[2]}]}]}];
const target={planId:1,sessionId:11,groupId:21,cook:false};
assert.equal(current(state,target,1,'Klimaat-Experience'),true);
assert.equal(current(state,target,2,'Klimaat-Experience'),false); // Other group's guide may be chosen.
assert.equal(current(state,{...target,groupId:22},2,'Klimaat-Experience'),true);
assert.equal(current(state,{...target,cook:true},3,'Voedsel-Innovatie'),true);
assert.equal(current(state,{...target,cook:true},1,'Voedsel-Innovatie'),false);
assert.equal(current(state,target,1,'Voedsel-Innovatie'),false); // Repairs inconsistent VI team.
const changed=structuredClone(state);changed[0].sessions[0].groups[0].staffIds=[2];
assert.equal(current(changed,target,2,'Klimaat-Experience'),true); // Immediate guard before refreshed response.
assert.equal(current(changed,target,1,'Klimaat-Experience'),false);
assert.equal(current(changed,target,2,'Voedsel-Innovatie'),true);
changed[0].sessions[0].groups[0]={id:21,kind:'school',staffIds:[]};
assert.equal(current(changed,target,2,'Klimaat-Experience'),false);
assert.equal(current(state,undefined,1,'Klimaat-Experience'),false);
assert.equal(current(state,{...target,planId:99},1,'Klimaat-Experience'),false);
assert.equal(current(state,{...target,groupId:99},1,'Klimaat-Experience'),false);
assert.deepEqual(state[0].sessions[0].groups[0].staffIds,[1]);
console.log('PASS: 14 candidate selection cases, current group/cook, other KE group, VI team repair and immediate draft guard');
