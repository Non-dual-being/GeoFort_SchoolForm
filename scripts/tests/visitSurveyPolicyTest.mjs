import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
const file = new URL('../../resources/js/survey/policy.ts', import.meta.url);
const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } });
const { wordCount, ratings } = await import('data:text/javascript;base64,' + Buffer.from(code.outputText).toString('base64'));
for (const [text, count] of [['',0], ['één l’été 你好 leerling-docent 😀',4], ['e\u0301e\u0301n woord',2], ['woord '.repeat(50),50], ['woord '.repeat(51),51], ['eerste\n\ntweede',2]]) assert.equal(wordCount(text),count);
assert.deepEqual(ratings.map(r=>r.value), ['dissatisfied','neutral','satisfied']);
console.log('PASS survey client Unicode word count, 50/51 boundary and balanced rating identifiers');
