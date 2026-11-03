import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
// Compile the real helper with its dependencies; no duplicated policy implementation.
const cache = new Map();
function load(file) {
  file = path.resolve(file); if (cache.has(file)) return cache.get(file);
  const exports = {}; cache.set(file, exports);
  const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('exports','require',js)(exports, spec => {
    const resolved = path.resolve(path.dirname(file), spec);
    return load(fs.existsSync(resolved) ? resolved : resolved + '.ts');
  });
  return exports;
}
const {getAvailableProgramOptions,isSelectedProgramStillAvailable,isProgramOpenOnDate,isPublicOfferDateAvailable} = load('resources/js/config/booking/educationProgramHelpers.ts');
const programs = {ochtend:{allowedSchoolTypes:['primairOnderwijs'],allowedWeekdays:[3]},dag:{allowedSchoolTypes:['primairOnderwijs','voortgezetOnderbouw'],allowedWeekdays:[1,2,3,4,5]}};
const period={program:'morning',from:'2026-12-08',until:'2026-12-08',weekdays:[2],open:true};
const config={programs,availability:{fiveModuleOpen:true,revision:1,periods:[period]}};
const available = (config,date,sector='primairOnderwijs') => getAvailableProgramOptions({config,visitDate:date,sector}).map(p=>p.key).sort();
assert.deepEqual(available(config,'2026-12-08'),['dag','ochtend']);
assert.deepEqual(available(config,'2026-12-15'),['dag']);
assert.deepEqual(available(config,'2026-12-08','voortgezetOnderbouw'),['dag']);
const closed={...config,availability:{...config.availability,fiveModuleOpen:false}};
assert.deepEqual(available(closed,'2026-12-08'),['ochtend']);
assert.deepEqual(available(closed,'2026-12-15'),[]);
assert.equal(isSelectedProgramStillAvailable({config:closed,visitDate:'2026-12-08',sector:'primairOnderwijs',selectedProgram:'dag'}),false);
const four={...closed,programVersion:'day-four-2027',availableFrom:'2027-01-04'};
assert.deepEqual(available(four,'2027-01-06'),['dag','ochtend']);
assert.deepEqual(available(four,'2026-12-08'),['ochtend']);
assert.equal(isProgramOpenOnDate(closed,'dag','2027-01-06'),false);
assert.equal(isProgramOpenOnDate(four,'dag','2027-01-06'),true);
const source={...closed,fourModuleConfig:four};
assert.equal(isPublicOfferDateAvailable(source,'auto','2026-12-08'),true);
assert.equal(isPublicOfferDateAvailable(source,'auto','2026-12-15'),false);
assert.equal(isPublicOfferDateAvailable(source,'auto','2027-01-05'),true);
assert.equal(isPublicOfferDateAvailable(source,'five','2027-01-05'),false);
assert.equal(isPublicOfferDateAvailable(source,'five','2027-01-06'),true);
const {createAgendaDayInfo} = load('resources/js/config/booking/calendar/helpers.ts');
const calendarDate={date:'2098-12-08',dateObj:new Date(2098,11,8),disabledByDateList:false,programAvailable:false};
assert.equal(createAgendaDayInfo(calendarDate).label,'Geen programma beschikbaar');
assert.equal(createAgendaDayInfo({...calendarDate,programAvailable:true}).status,'bookable');
assert.equal(createAgendaDayInfo({...calendarDate,disabledDetail:{datum:calendarDate.date,type:'school_vacation',reden:'Vakantie'}}).reason,'school_vacation');
const {validateProgramConfigurationStep} = load('resources/js/admin/validation/programConfigurationStepValidator.ts');
const context={schoolSector:'primairOnderwijs',weekday:2,options:[{key:'ochtend',...programs.ochtend,availableOnVisitDate:true}],confirmations:{program:true},weekdayAccepted:false};
assert.deepEqual(validateProgramConfigurationStep('program',{program:'ochtend'},context),[]);
assert.equal(validateProgramConfigurationStep('program',{program:'ochtend'},{...context,weekdayAccepted:true,options:[{...context.options[0],availableOnVisitDate:false}]})[0].code,'PROGRAM_NOT_OPEN');
console.log('PASS real public options: extra Tuesday, sector, closure, morning retained, four/five versions and invalidated selection');
