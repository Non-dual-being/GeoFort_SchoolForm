import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

// Exercise Vite's actual generated preload helper, including its stylesheet
// load/error events. The PHP backend publishes assets under /build/, not /assets/.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const build = path.join(root, 'public/build');
const manifest = JSON.parse(await fs.readFile(path.join(build, '.vite/manifest.json'), 'utf8'));
const entry = manifest['resources/js/admin.ts'];
const bundle = await fs.readFile(path.join(build, entry.file), 'utf8');
const source = ts.createSourceFile('admin.js', bundle, ts.ScriptTarget.Latest, true, ts.ScriptKind.JS);
let helper;
for (const statement of source.statements) {
  if (!ts.isVariableStatement(statement)) continue;
  const declarations = [...statement.declarationList.declarations];
  const index = declarations.findIndex(d => d.initializer &&
    (ts.isFunctionExpression(d.initializer) || ts.isArrowFunction(d.initializer)) &&
    d.initializer.getText(source).includes('vite:preloadError'));
  if (index >= 0) {
    helper = 'const ' + declarations.slice(0, index + 1).map(d => d.getText(source)).join(',') +
      '; globalThis.preload = ' + declarations[index].name.getText(source) + ';';
    break;
  }
}
assert.ok(helper, 'Generated Vite preload helper must be tested, not skipped');
const origin = process.env.PRODUCTION_ASSET_ORIGIN;
if (origin) assert.match(origin, /^https:\/\/[^/]+$/);

function dependencies(key, found = new Set()) {
  const chunk = manifest[key];
  assert.ok(chunk, 'Manifest dependency exists: ' + key);
  if (found.has(chunk.file)) return found;
  found.add(chunk.file);
  for (const css of chunk.css ?? []) found.add(css);
  for (const dependency of chunk.imports ?? []) dependencies(dependency, found);
  return found;
}

function page() {
  const links = [], loads = [], events = [];
  const document = {
    getElementsByTagName: () => [], querySelector: () => null,
    createElement: () => {
      const listeners = new Map();
      return { addEventListener: (type, cb) => listeners.set(type, cb),
        setAttribute() {}, fire: type => listeners.get(type)?.() };
    },
    head: { appendChild(link) {
      links.push(link);
      loads.push(new Promise(resolve => setImmediate(async () => {
        try {
          assert.ok(link.href.startsWith('/build/'), 'Asset escaped PHP build mount: ' + link.href);
          const relative = link.href.slice('/build/'.length);
          assert.ok(!relative.includes('..'));
          const bytes = await fs.readFile(path.join(build, relative));
          assert.ok(bytes.length);
          if (origin) {
            const response = await fetch(origin + link.href, { signal: AbortSignal.timeout(15000) });
            assert.equal(response.status, 200, link.href);
            const contentType = response.headers.get('content-type') ?? '';
            assert.match(contentType, link.rel === 'stylesheet' ? /text\/css/ : /javascript/);
            assert.deepEqual(Buffer.from(await response.arrayBuffer()), bytes, link.href);
          }
          link.fire('load'); resolve(null);
        } catch (error) { link.fire('error'); resolve(error); }
      })));
    } },
  };
  const context = vm.createContext({ document, window: { dispatchEvent: e => events.push(e) }, Event });
  new vm.Script(helper).runInContext(context);
  return { preload: context.preload, links, loads, events };
}

const survey = 'resources/js/admin/views/DashboardVisitSurveysView.vue';
assert.ok(entry.dynamicImports.includes(survey));
assert.ok(manifest[survey].css?.length, 'Survey styling must be covered');
for (const route of entry.dynamicImports) {
  const assets = [...dependencies(route)];
  for (const scenario of ['direct load', 'refresh']) {
    const context = page();
    let mounted = false;
    await context.preload(async () => { mounted = true; return {}; }, assets);
    assert.equal(mounted, true, route + ': ' + scenario);
    const results = await Promise.all(context.loads);
    for (const failure of results) if (failure) throw failure;
    assert.equal(context.events.length, 0, 'No preload errors');
    const count = context.links.length;
    await context.preload(async () => ({}), assets);
    assert.equal(context.links.length, count, 'Revisiting a route reuses loaded assets');
  }
}
console.log(`PASS production assets: ${entry.dynamicImports.length} lazy dashboard routes, direct load, refresh, revisit, CSS and JS under /build/${origin ? '; live HTTPS contents verified' : ''}`);
