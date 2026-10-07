import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';

const source = fs.readFileSync(new URL('../../resources/js/admin/validation/programConfigurationStepValidator.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } });
const { validateProgramConfigurationStep } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
const context = { studentLimits: { minimum: 1, maximum: null }, confirmations: { students: true } };
for (const studentCount of [1, 28, 39, 40, 80, 160, 161, 250, 1001]) {
  assert.deepEqual(validateProgramConfigurationStep('students', { studentCount }, context), []);
}
for (const studentCount of [0, -1, 12.5, '28', null, true, {}, [], Number.NaN]) {
  assert.ok(validateProgramConfigurationStep('students', { studentCount }, context).some(issue => issue.field === 'studentCount'));
}
console.log('OK: actual dashboard program step accepts positive integers without an upper limit.');
