import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),ts=require('typescript');
const source=fs.readFileSync(new URL('../../resources/js/admin/services/rosterJointIssuePresentation.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText;
const {presentJointIssue}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const plans=[
  {id:17,school:{name:'Waterkant'},groups:[{id:1,label:'Groep 1'}],planning:{modules:[{key:'Voedsel-Innovatie',label:'Voedsel Innovatie'}],sessions:[{id:1,moduleKey:'Voedsel-Innovatie',moduleLabel:'Voedsel Innovatie',startTime:'10:15',endTime:'11:00',groupIds:[1]}],staffCatalog:[]}},
  {id:18,school:{name:'Rivier'},groups:[{id:2,label:'Groep 2'}],planning:{modules:[{key:'Klimaat-Experience',label:'Klimaat Experience'}],sessions:[{id:2,moduleKey:'Klimaat-Experience',moduleLabel:'Klimaat Experience',startTime:'11:00',endTime:'11:45',groupIds:[2]}],staffCatalog:[{id:8,name:'Begeleider'}]}},
];
const original=JSON.stringify(plans);
const issue={code:'FOUR_MODULE_KE_SUPERVISION_REQUIRED',severity:'error',message:'Schoolbegeleiding blokkeert definitief maken.',planId:18,sessionId:2,groupId:2};
const ke=presentJointIssue(issue,plans);
assert.deepEqual(ke.schools,['Rivier']);assert.deepEqual(ke.modules,['Klimaat Experience']);assert.equal(ke.category,'Verplichte begeleiding');assert.deepEqual(ke.context,['11:00–11:45','Groep 2']);assert.deepEqual(ke.target,{planId:18,sessionId:2,groupId:2});
const both=presentJointIssue({code:'STAFF_DAY_OVERLAP',message:'Overlap',schools:[{planId:17,schoolName:'Old name'},{planId:18,schoolName:'Rivier'}],sessionIds:[1,2],startTime:'10:30',endTime:'10:45',staffId:8},plans);
assert.deepEqual(both.schools,['Waterkant','Rivier']);assert.deepEqual(both.modules,['Voedsel Innovatie','Klimaat Experience']);assert.deepEqual(both.context,['10:30–10:45','Begeleider']);assert.equal(both.target,null);
const outside=presentJointIssue({code:'MODULE_CAPACITY',message:'Capacity',schools:[{planId:90,schoolName:'Externe school'}],moduleKey:'Voedsel-Innovatie'},plans);
assert.deepEqual(outside.schools,['Externe school']);assert.equal(outside.category,'Modulecapaciteit');assert.equal(outside.target,null);
const missing=presentJointIssue({...issue,sessionId:999},plans);assert.equal(missing.target,null);assert.deepEqual(missing.modules,['Roosterbreed']);
const generic=presentJointIssue({code:'FUTURE_RULE',message:'Rivier Klimaat Experience'},plans);assert.deepEqual(generic.schools,['Gezamenlijke planning']);assert.deepEqual(generic.modules,['Roosterbreed']);assert.equal(generic.category,'Roostercontrole');
const noSession=presentJointIssue({code:'MISSING_MODULE',message:'Missing',planId:17,groupId:1,moduleKey:'Klimaat-Experience'},plans);assert.deepEqual(noSession.modules,['Klimaat Experience']);assert.equal(noSession.target,null);
const preview=structuredClone(plans);preview[1].planning.sessions[0].startTime='13:00';preview[1].planning.sessions[0].endTime='13:45';assert.equal(presentJointIssue(issue,preview).context[0],'13:00–13:45');
assert.equal(JSON.stringify(plans),original);
console.log('PASS joint issue presentation: school/module tags, cross-school conflicts, external schools, safe missing metadata, correct lesson targets and draft times; source unchanged');
