import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

// Run the actual TypeScript helpers, including the shared public availability policy.
const cache = new Map();
function load(file) {
  file = path.resolve(file);
  if (cache.has(file)) return cache.get(file);
  const exports = {}; cache.set(file, exports);
  const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('exports', 'require', js)(exports, spec => {
    const resolved = path.resolve(path.dirname(file), spec);
    return load(fs.existsSync(resolved) ? resolved : resolved + '.ts');
  });
  return exports;
}
const {periodBounds, inferPeriodSelection, periodHasWeekday, periodSummary, programDateExplanation, validDate} = load('resources/js/admin/utils/programConfiguration.ts');
const base = {kind:'months',year:2027,firstMonth:3,lastMonth:6,from:'',until:''};
assert.deepEqual(periodBounds(base), {from:'2027-03-01',until:'2027-06-30'});
assert.deepEqual(periodBounds({...base,kind:'year'}), {from:'2027-01-01',until:'2027-12-31'});
assert.deepEqual(periodBounds({...base,year:2028,firstMonth:2,lastMonth:2}), {from:'2028-02-01',until:'2028-02-29'});
assert.deepEqual(periodBounds({...base,firstMonth:2,lastMonth:2}), {from:'2027-02-01',until:'2027-02-28'});
for (const invalid of [{firstMonth:6,lastMonth:3},{firstMonth:0},{lastMonth:13},{year:0},{year:2027.5},{year:''}]) assert.equal(periodBounds({...base,...invalid}),null);
assert.deepEqual(periodBounds({...base,kind:'day',from:'2026-12-08'}), {from:'2026-12-08',until:'2026-12-08'});
assert.deepEqual(periodBounds({...base,kind:'custom',from:'2026-12-08',until:'2027-01-15'}), {from:'2026-12-08',until:'2027-01-15'});
assert.equal(periodBounds({...base,kind:'custom',from:'2027-01-15',until:'2026-12-08'}),null);
for (const date of ['2027-02-29','2027-04-31','2027-13-01','2027-1-1','']) assert.equal(validDate(date),false);
assert.equal(validDate('2028-02-29'),true);
const rule = {program:'morning',from:'2026-12-08',until:'2026-12-08',weekdays:[2],open:true,note:''};
// Opening the editor must never expand a previously saved period.
for (const [from,until,kind] of [
  ['2026-12-08','2026-12-08','day'],['2027-01-01','2027-12-31','year'],
  ['2028-02-01','2028-02-29','months'],['2027-03-01','2027-06-30','months'],
  ['2027-03-02','2027-06-30','custom'],['2026-12-01','2027-02-28','custom'],
]) {
  const selection = inferPeriodSelection({...rule,from,until});
  assert.equal(selection.kind,kind);
  assert.deepEqual(periodBounds(selection),{from,until});
}
assert.equal(periodHasWeekday(rule),true);
assert.equal(periodHasWeekday({...rule,weekdays:[3]}),false);
assert.equal(periodHasWeekday({...rule,until:'2026-12-09',weekdays:[3]}),true);
assert.equal(periodSummary(rule),'Open op dinsdag 8 december 2026.');
const config = {fiveModuleOpen:false,revision:1,periods:[rule],updatedAt:null};
assert.equal(programDateExplanation(config,'morning','2026-12-08').open,true);
assert.match(programDateExplanation(config,'morning','2026-12-08').explanation,/Ingestelde periode/);
assert.equal(programDateExplanation(config,'morning','2026-12-15').open,false);
assert.equal(programDateExplanation(config,'morning','2026-12-09').open,true);
assert.equal(programDateExplanation(config,'five','2027-01-06').open,false);
assert.equal(programDateExplanation(config,'four','2027-01-06').open,true);
assert.equal(programDateExplanation(config,'four','2027-01-01').open,false);
assert.equal(programDateExplanation(config,'four','2027-01-09').open,false);
assert.equal(programDateExplanation(config,'morning','invalid').open,false);
const closure = {...config,periods:[{...rule,from:'2026-12-09',until:'2026-12-09',weekdays:[3],open:false}]};
assert.equal(programDateExplanation(closure,'morning','2026-12-09').open,false);
console.log('PASS configuration presentation: calendar ranges, leap years, exact edit roundtrip, weekdays and shared program date policy');
