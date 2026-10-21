import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
const source = fs.readFileSync(new URL('../../resources/js/admin/services/rosterStaffingState.ts', import.meta.url), 'utf8');
const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const { rosterStaffingState: state, jointLessonsAvailable } = await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
const settings = { staffingMode:'lesson_only', cookStaffId:null };
const person = { id:1, name:'Test guide' };
const lesson = { id:1, moduleKey:'Earth-Watch', groupIds:[1], minimumGeoFortStaff:1, schoolSupervisionAllowed:false, staffAssignments:[], groupSupervision:[{groupId:1,kind:'open'}] };
assert.equal(state([],settings).state,'empty');
assert.equal(state([lesson],settings).state,'none');
const filled = { ...lesson, staffAssignments:[person], groupSupervision:[{groupId:1,kind:'staff'}] };
assert.equal(state([filled],settings).state,'complete'); // original lesson-only choice is irrelevant
assert.equal(state([filled,lesson],settings).state,'partial');
const school = { ...lesson, moduleKey:'Klimaat-Experience', minimumGeoFortStaff:0, schoolSupervisionAllowed:true, groupSupervision:[{groupId:1,kind:'school'}] };
assert.equal(state([school],settings).state,'complete');
assert.equal(state([{...school,schoolSupervisionAllowed:false}],settings).state,'none');
const pair = { ...filled, moduleKey:'Klimaat-Experience', groupIds:[1,2], groupSupervision:[{groupId:1,kind:'staff'},{groupId:2,kind:'staff'}] };
assert.equal(state([pair],settings).state,'complete'); // legitimate 1 guide / 2 groups
assert.equal(state([{...pair,groupStaffAssignments:[{groupId:1,staffAssignments:[person]},{groupId:2,staffAssignments:[]}]}],settings).state,'partial');
assert.equal(state([{...pair, minimumGeoFortStaff:2}],settings).state,'partial');
const food={...filled,moduleKey:'Voedsel-Innovatie'};
assert.equal(state([food],settings).state,'partial');
assert.equal(state([food],{...settings,cookStaffId:2}).state,'complete');
assert.equal(state([{...lesson,staffAssignments:[person]}],settings).state,'none'); // explicit open overrides legacy union
assert.equal(jointLessonsAvailable([{planning:{sessions:[lesson]}},{planning:{sessions:[lesson]}}]),true);
assert.equal(jointLessonsAvailable([{planning:{sessions:[filled]}},{planning:{sessions:[]}}]),false);
console.log('PASS empty / none / partial / complete, school, cook, group-specific and shared supervision, lesson-first joint overview');
