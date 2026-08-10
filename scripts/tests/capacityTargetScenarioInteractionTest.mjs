import {
  capacityTargetScenarioChanged,
  capacityTargetScenarioStatus,
  deriveCapacityTargetAverage,
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

console.log("Capacity target scenario interaction tests passed.");
