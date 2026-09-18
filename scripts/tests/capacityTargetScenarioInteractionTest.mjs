import {
  capacityTargetScenarioChanged,
  capacityTargetScenarioStatus,
  deriveCapacityTargetAverage,
  scenarioCapacityRows,
} from "../../resources/js/admin/utils/capacityTargetScenario.ts";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const baseline = {
  effectiveDate: "2026-08-07",
  studentsPerAvailableDay: 120,
  bookingsPerAvailableDay: 1.5,
};

assert(!capacityTargetScenarioChanged({ ...baseline }, baseline), "Ongewijzigde opgeslagen invoer wordt als scenario gemarkeerd.");
assert(capacityTargetScenarioChanged({ ...baseline, studentsPerAvailableDay: 125 }, baseline), "Gewijzigde invoer wordt niet als scenario gemarkeerd.");
assert(capacityTargetScenarioStatus(false, false) === "Nog geen officieel target", "Lege beginsituatie heeft de verkeerde status.");
assert(capacityTargetScenarioStatus(false, true) === "Nieuw scenario", "Eerste invoer heeft de verkeerde status.");
assert(capacityTargetScenarioStatus(true, false) === "Officieel opgeslagen target", "Ongewijzigd opgeslagen target heeft de verkeerde status.");
assert(capacityTargetScenarioStatus(true, true) === "Niet-opgeslagen scenario", "Gewijzigd opgeslagen target heeft de verkeerde status.");
assert(deriveCapacityTargetAverage(120, 1.5) === 80, "Afgeleid gemiddeld target wordt niet correct berekend.");
assert(deriveCapacityTargetAverage(120, 0) === null, "Boekingtarget nul leidt tot delen door nul.");
assert(deriveCapacityTargetAverage(null, 1.5) === null, "Ontbrekende leerlinginvoer levert ten onrechte een gemiddelde op.");

const closedDay = { date: '2026-08-15', month: '2026-08', available: false, countsForCapacity: true, evaluationIncluded: true, studentsActual: 60, bookingsActual: 1, officialTarget: null };
const newTarget = { ...baseline, effectiveDate: '2026-08-15' };
const scenarioRows = scenarioCapacityRows([
  { month: '2026-08', periodState: 'current', technicalCapacity: { students: 160, bookings: 2 } },
], { today: '2026-08-15', daySnapshots: [closedDay] }, newTarget);
assert(scenarioRows[0].targetAvailableDays === 1 && scenarioRows[0].studentsTargetComparison.target === 120 && scenarioRows[0].bookingsTargetComparison.target === 1.5, 'Scenario verwijdert target van gesloten dag met planning.');
const inactiveRows = scenarioCapacityRows([
  { month: '2026-08', periodState: 'current', technicalCapacity: { students: 0, bookings: 0 } },
], { today: '2026-08-15', daySnapshots: [{ ...closedDay, countsForCapacity: false, studentsActual: 0, bookingsActual: 0 }] }, newTarget);
assert(inactiveRows[0].targetAvailableDays === 0 && inactiveRows[0].studentsTargetComparison.target === null, 'Scenario activeert gesloten dag zonder meetellende planning.');

console.log("Capacity target scenario interaction tests passed.");
