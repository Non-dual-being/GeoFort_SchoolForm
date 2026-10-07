import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';

const source = fs.readFileSync(new URL('../../resources/js/admin/utils/rosterPresentation.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } });
const { rosterModulesForStaff, rosterModulesForSchool, groupRosterModules } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
const cases = JSON.parse(fs.readFileSync(new URL('./fixtures/RosterPresentationCases.json', import.meta.url), 'utf8'));
const ordered = rows => rows.sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));
for (const fixture of cases) {
  const before = JSON.stringify(fixture);
  const actual = [];
  const append = (responsible, modules) => {
    for (const module of modules) actual.push({
      responsible, time: module.key.split('|').slice(0, 2).join('|'), moduleKey: module.moduleKey,
      text: `${module.isCook ? 'Kok · ' : ''}${module.moduleLabel}\n${module.groupLabels.map(label => `(${label})`).join(' ')}`,
    });
  };
  for (const staff of fixture.staffCatalog) append(`staff:${staff.id}`, rosterModulesForStaff(fixture.sessions, fixture.groups, staff.id, fixture.cookStaffId));
  append('school', rosterModulesForSchool(fixture.sessions, fixture.groups));
  assert.deepEqual(ordered(actual), ordered(fixture.expected.filter(row => row.responsible !== 'open')), fixture.name);
  assert.equal(JSON.stringify(fixture), before, 'Presentation mutated source assignments');
  assert.equal(fixture.sessions.some(session => session.staffAssignments.some(staff => staff.id === fixture.cookStaffId)), false, 'Cook fixture must have no guide assignment');
}
const session = cases[0].sessions[0];
const natural = groupRosterModules([{ session: { ...session, groupIds: [10, 2, 1], groupLabels: ['Groep 10', 'Groep 2', 'Groep 1'] }, groupIds: [10, 2, 1, 2] }], []);
assert.deepEqual(natural[0].groupLabels, ['Groep 1', 'Groep 2', 'Groep 10']);
console.log('OK: staff/School grouping, group-only assignments, cook role, duplicates, multiple modules/times, ordering and immutable input.');
