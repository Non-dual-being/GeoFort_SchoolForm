import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
const source = fs.readFileSync(new URL('../../resources/js/admin/services/scrollToRosterAction.ts', import.meta.url), 'utf8');
const code = ts.transpileModule(source.replace('import { nextTick } from "vue";', 'const nextTick = async () => { await Promise.resolve(); };'), {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
}).outputText;
const { scrollToRosterAction } = await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
let reduced = false;
globalThis.window = { innerHeight: 800, matchMedia: query => { assert.equal(query, '(prefers-reduced-motion: reduce)'); return { matches: reduced }; } };
const calls = [];
const element = top => ({ isConnected: true, style: {}, getBoundingClientRect: () => ({ top }), scrollIntoView: options => calls.push(options) });
await scrollToRosterAction(() => null);
await scrollToRosterAction(() => ({ ...element(900), isConnected: false }));
await scrollToRosterAction(() => element(120));
assert.equal(calls.length, 0, 'No jump for absent, detached or already visible targets');
let target = null;
const pending = scrollToRosterAction(() => target);
target = element(1000);
await pending;
assert.deepEqual(calls.pop(), { behavior: 'smooth', block: 'start' });
assert.equal(target.style.scrollMarginTop, '1rem', 'New editor gets a small viewport margin');
reduced = true;
await scrollToRosterAction(() => element(-200));
assert.deepEqual(calls.pop(), { behavior: 'auto', block: 'start' });
assert.equal(calls.length, 0);
console.log('PASS targeted scrolling: waits for render, visible targets stay still, offscreen targets scroll, reduced motion respected');
