import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import {createSSRApp,h} from 'vue';
import {renderToString} from 'vue/server-renderer';
import {parse,compileScript} from 'vue/compiler-sfc';
import ts from 'typescript';

const require=createRequire(import.meta.url);
function load(source,overrides={}) {
  const js=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText;
  const module={exports:{}};
  new Function('require','module','exports',js)(name=>overrides[name]??require(name),module,module.exports);
  return module.exports;
}
const utils=load(fs.readFileSync(new URL('../../resources/js/admin/utils/rosterPresentation.ts',import.meta.url),'utf8'));
const source=fs.readFileSync(new URL('../../resources/js/admin/components/rosters/RosterJointStaffTimeline.vue',import.meta.url),'utf8');
const {descriptor}=parse(source);
const compiled=compileScript(descriptor,{id:'school-timeline-test',inlineTemplate:true,templateOptions:{ssr:true}});
const component=load(compiled.content,{
  '../../utils/rosterPresentation':utils,
  './RosterStaffLetter.vue':{default:{props:['letter'],setup:p=>()=>h('span',p.letter)}},
}).default;
function session(id,moduleKey,start,end,groupIds,kinds) {
  return {id,moduleKey,moduleLabel:moduleKey,color:'#aabbcc',startTime:start,endTime:end,groupIds,groupLabels:groupIds.map(id=>'Groep '+id),
    groupSupervision:groupIds.map((groupId,i)=>({groupId,kind:kinds[i]})),
    staffAssignments:[{id:10,name:'Testdocent'}],
    groupStaffAssignments:groupIds.map((groupId,i)=>({groupId,staffAssignments:kinds[i]==='staff'?[{id:10,name:'Testdocent'}]:[]})),cookStaffId:null};
}
function plan(id,name,sessions) {
  return {id,school:{name},groups:[1,2,3].map(id=>({id,label:'Groep '+id,position:id})),planning:{sessions,
    rounds:[{type:'arrival',label:'Welkom',startTime:'10:00',endTime:'10:15'},{type:'break',label:'Pauze',startTime:'11:00',endTime:'11:15'}],
    staffingSettings:{cookStaffId:null},staffCatalog:[{id:10,name:'Testdocent'}]}};
}
const first=plan(1,'School Noord',[session(1,'Vleermuizen','10:15','11:00',[1,2],['school','staff']),session(2,'KE','11:15','12:00',[1,2],['school','open'])]);
const second=plan(2,'School Zuid',[session(3,'Vleermuizen','10:15','10:35',[1],['school']),session(4,'Earth Watch','10:35','11:00',[1],['school'])]);
const render=plans=>renderToString(createSSRApp(component,{plans,letters:{10:'A'},approved:[]}));
const before=JSON.stringify([first,second]);
let html=await render([first,second]);
assert.equal((html.match(/class="joint-overview__school-heading"/g)??[]).length,2);
assert(html.includes('School Noord')&&html.includes('School Zuid')&&html.includes('Testdocent'));
assert(html.includes('Schoolbegeleiding'));
assert(html.includes('rowspan="2" class="joint-overview__school-cell"'),'School lesson must span both other-school rounds');
const north=utils.rosterModulesForSchool(first.planning.sessions,first.groups);
assert.deepEqual(north.map(m=>m.groupIds),[[1],[1]],'Staff/open groups incorrectly labelled school');
assert.equal(north[0].startTime,'10:15');assert.equal(north[0].endTime,'11:00');
assert.equal(JSON.stringify([first,second]),before,'Rendered timetable changed source data');

const onlySchool=structuredClone(first);
onlySchool.planning.sessions=onlySchool.planning.sessions.slice(0,1);
onlySchool.planning.sessions[0].groupSupervision.forEach(group=>group.kind='school');
html=await render([onlySchool]);
assert(!html.includes('Er zijn nog geen docenten'),'School-only schedule hidden behind empty staff state');
assert(!html.includes('Testdocent'),'Stale staff union should not become an assignment');
assert(html.includes('(Groep 1) (Groep 2)')&&html.includes('10:15–11:00'));
assert(html.includes('Welkom')&&html.includes('Pauze'),'School day blocks lost');

const draft=structuredClone(onlySchool);
draft.planning.sessions[0].groupSupervision.forEach(group=>group.kind='staff');
draft.planning.sessions[0].groupStaffAssignments.forEach(group=>group.staffAssignments=[{id:10,name:'Testdocent'}]);
html=await render([draft]);
assert(!html.includes('Schoolbegeleiding')&&html.includes('Testdocent'),'Draft changes not reflected in school column');
const empty=plan(3,'Empty',[]);html=await render([empty]);assert(html.includes('Er zijn nog geen docenten'));

const separate=plan(4,'School <veilig>',[session(5,'KE','10:15','11:00',[1],['school']),session(6,'KE','10:15','11:00',[2],['school'])]);
html=await render([separate]);assert(html.includes('(Groep 1) (Groep 2)'));
assert(html.includes('School &lt;veilig&gt;'),'School name not escaped');
console.log('PASS: rendered single/joint school columns, school-only schedule, mixed supervision, differing time slots, rowspans, live draft, neutral blocks and unchanged source');
