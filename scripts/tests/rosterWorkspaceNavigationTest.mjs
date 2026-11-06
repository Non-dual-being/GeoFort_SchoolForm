import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
const source=fs.readFileSync(new URL('../../resources/js/admin/services/rosterWorkspaceNavigation.ts',import.meta.url),'utf8');
const js=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
const {manualWorkspaceLocation,linkedWorkspaceLocation}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const single={id:5,jointPlanning:null};
assert.equal(linkedWorkspaceLocation(single),null);
assert.deepEqual(manualWorkspaceLocation(single),{name:'roster-manual',params:{id:5}});
for(const id of [17,18]){
 const plan={id,jointPlanning:{planIds:[17,18]}};const before=JSON.stringify(plan);
 assert.deepEqual(manualWorkspaceLocation(plan),{name:'roster-create',query:{schools:'2',school1:'17',school2:'18',joint:'1',focus:String(id)}});
 assert.equal(JSON.stringify(plan),before);
}
console.log('PASS manual navigation: one school uses single workspace, linked schools use joint workspace with correct focus, input unchanged');
