import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { parse, compileScript } from "vue/compiler-sfc";
import ts from "typescript";

// Run the actual SFC, router and API client with an in-memory Vue renderer and fake HTTP.
const require = createRequire(import.meta.url);
const Vue = require("vue");
const Router = require("vue-router");
const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const cache = new Map();
const fieldStub = Vue.defineComponent({
  props: ["modelValue", "name"],
  emits: ["update:modelValue"],
  setup(props, { emit }) {
    return () => Vue.h("input", { name: props.name, onChange: value => emit("update:modelValue", value) });
  },
});
function loadModule(path) {
  if (cache.has(path)) return cache.get(path);
  if (/Admin(?:DateField|Select)\.vue$/.test(path)) return { __esModule: true, default: fieldStub };
  const source = readFileSync(path, "utf8");
  const content = path.endsWith(".vue")
    ? compileScript(parse(source, { filename: path }).descriptor, { id: path, inlineTemplate: true }).content
    : source;
  const { outputText } = ts.transpileModule(content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
  });
  const module = { exports: {} };
  cache.set(path, module.exports);
  new Function("require", "module", "exports", outputText)(specifier => {
    if (!specifier.startsWith(".")) return require(specifier);
    const dependency = resolve(dirname(path), specifier);
    return loadModule(/\.(vue|ts)$/.test(dependency) ? dependency : `${dependency}.ts`);
  }, module, module.exports);
  return module.exports;
}

const makeNode = (type, text = "") => ({ type, text, props: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {}, getRootNode: () => globalThis.document });
const renderer = Vue.createRenderer({
  createElement: type => makeNode(type), createText: text => makeNode("text", text), createComment: text => makeNode("comment", text),
  setText: (node, text) => { node.text = text; },
  setElementText: (node, text) => { node.text = text; node.children = []; },
  patchProp: (node, key, _previous, value) => { node.props[key] = value; },
  parentNode: node => node.parent,
  nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
  insert(node, parent, anchor = null) {
    if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
    node.parent = parent;
    const index = anchor ? parent.children.indexOf(anchor) : -1;
    if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node);
  },
  remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); },
});
globalThis.document = { querySelector: () => null, activeElement: null };
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const requests = [];
globalThis.fetch = async url => {
  const query = new URL(url, "https://example.test").searchParams;
  requests.push(Object.fromEntries(query));
  const page = Number(query.get("page") || 1);
  const items = Array.from({ length: 20 }, (_, index) => ({
    id: (page - 1) * 20 + index + 1, status: "In optie", visitDate: "2027-03-10", schoolName: "Sort school",
    city: "Plaats", sectorLabel: "Primair onderwijs", programLabel: "Dag", moduleLabel: "Earth-Watch", studentCount: 40, contactPersonName: "Sanne",
  }));
  return new Response(JSON.stringify({ ok: true, data: {
    items, pagination: { currentPage: page, perPage: 20, totalItems: 60, totalPages: 3, from: (page - 1) * 20 + 1, to: page * 20 },
    filters: { statuses: [], sectors: [], programs: [], modules: [], dateRange: { min: null, max: null } },
  } }), { headers: { "Content-Type": "application/json" } });
};
const view = loadModule(resolve(root, "resources/js/admin/views/DashboardBookingsView.vue")).default;
const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise(setImmediate); await Vue.nextTick(); } };
async function mount(url) {
  const router = Router.createRouter({ history: Router.createMemoryHistory(), routes: [
    { path: "/aanvragen", name: "bookings", component: view },
    { path: "/aanvragen/:id", name: "booking-detail", component: { render: () => null } },
  ] });
  await router.push(url);
  const container = makeNode("root");
  const app = renderer.createApp(view);
  app.use(router);
  app.mount(container);
  await settle();
  return { app, router, container };
}
const text = node => node.type === "comment" ? "" : node.text + node.children.map(text).join("");
function find(node, predicate) {
  if (predicate(node)) return node;
  for (const child of node.children) { const found = find(child, predicate); if (found) return found; }
}
let state = await mount("/aanvragen");
const button = label => {
  const node = find(state.container, node => node.type === "button" && text(node).trim() === label);
  assert.ok(node, `Missing button: ${label}`);
  return node;
};
const click = async label => { button(label).props.onClick(); await settle(); };
assert.equal(button("Nieuwste eerst").props["aria-pressed"], true);
assert.equal(requests.at(-1).sort, "desc");
assert.equal(button("Filters wissen").props.disabled, true);
await click("Volgende");
assert.equal(requests.at(-1).page, "2");
assert.equal(requests.at(-1).sort, "desc");
const beforeSort = requests.length;
await click("Oudste eerst");
assert.equal(requests.length, beforeSort + 1, "Sort change should fetch once");
assert.equal(requests.at(-1).page, undefined);
assert.equal(requests.at(-1).sort, "asc");
assert.equal(button("Oudste eerst").props["aria-pressed"], true);
assert.equal(button("Filters wissen").props.disabled, false);
await click("Volgende");
assert.equal(requests.at(-1).sort, "asc");
assert.equal(requests.at(-1).page, "2");

const filterQuery = { search: "Sort school", status: "In optie", sector: "primairOnderwijs", program: "dag", module: "Earth-Watch", dateFrom: "2027-03-01", dateTo: "2027-03-30", sort: "asc", page: "2" };
await state.router.push({ name: "bookings", query: filterQuery });
await settle();
assert.deepEqual(requests.at(-1), filterQuery);
await click("Nieuwste eerst");
const expected = { ...filterQuery, sort: "desc" };
delete expected.page;
assert.deepEqual(requests.at(-1), expected);
await click("Volgende");
assert.deepEqual(requests.at(-1), { ...expected, page: "2" });
const detail = find(state.container, node => node.type === "a" && node.props.href?.includes("/aanvragen/21?"));
assert.ok(detail, "Detail link missing");
assert.deepEqual(Object.fromEntries(new URL(detail.props.href, "https://example.test").searchParams), { ...expected, page: "2" });

const refreshUrl = state.router.currentRoute.value.fullPath;
state.app.unmount();
state = await mount(refreshUrl);
assert.deepEqual(requests.at(-1), { ...expected, page: "2" });
assert.equal(button("Nieuwste eerst").props["aria-pressed"], true);
await click("Oudste eerst");
await click("Filters wissen");
assert.deepEqual(state.router.currentRoute.value.query, {});
assert.deepEqual(requests.at(-1), { sort: "desc" });
assert.equal(button("Nieuwste eerst").props["aria-pressed"], true);
state.router.back();
await settle();
assert.equal(button("Oudste eerst").props["aria-pressed"], true);
assert.equal(requests.at(-1).search, "Sort school");
await state.router.push("/aanvragen?sort=invalid");
await settle();
assert.equal(requests.at(-1).sort, "desc");
state.app.unmount();
console.log("OK: actual Vue sorting controls, requests, pagination, combined filters, detail links, refresh, reset and back navigation.");
