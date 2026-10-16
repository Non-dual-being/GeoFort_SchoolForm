<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import StaffDeploymentInsights, { type DeploymentInsights } from "../components/rosters/StaffDeploymentInsights.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import { getApiData } from "../../services/http/apiClient";
type Filters = { from: string; to: string; status: string; roster: string; staff: string; employment: string; costBasis: string; extraMinutes: string };
type Module = { module: string; role: string; minutes: number; rejectedMinutes: number; lessons: number };
type Row = { id: number; name: string; employment: string; minutes: number; statuses: Record<string, number>; schools: number; days: number; guideMinutes: number; cookMinutes: number; conceptMinutes: number; publishedMinutes: number; modules: Module[] };
type Entry = { staffId: number; date: string; planId: number; school: string; status: string; rosterStatus: string; start: string; end: string; moduleLabel: string; role: string; sessionId: number };
type Report = { insights: DeploymentInsights; filters: Filters; staffOptions: { id: number; name: string }[]; rows: Row[]; entries: Entry[] };
const year = new Date().getFullYear();
const filters = ref<Filters>({ from: `${year}-01-01`, to: `${year}-12-31`, status: "all", roster: "all", staff: "all", employment: "all", costBasis: "lessons", extraMinutes: "0" });
const report = ref<Report>();
const busy = ref(false), exporting = ref(false), error = ref("");
const selected = ref<number>();
const person = computed(() => report.value?.rows.find(r => r.id === selected.value));
const entries = computed(() => report.value?.entries.filter(e => e.staffId === selected.value) ?? []);
const hours = (minutes: number) => `${Math.floor(minutes / 60)} u ${minutes % 60 ? `${minutes % 60} min` : ""}`.trim();
const employment = (value: string) => value === "paid" ? "Betaald" : value === "volunteer" ? "Vrijwilliger" : "Onbekend";
const endpoint = "/api/admin/rosters/staff-deployment.php";
async function load() {
  busy.value = true; error.value = "";
  try { report.value = await getApiData<Report>(`${endpoint}?${new URLSearchParams(filters.value)}`); selected.value = report.value.rows.some(r => r.id === selected.value) ? selected.value : report.value.rows[0]?.id; }
  catch (e) { error.value = e instanceof Error ? e.message : "Laden mislukt."; }
  finally { busy.value = false; }
}
async function download() {
  if (!report.value) return;
  exporting.value = true; error.value = "";
  try {
    const response = await fetch(`${endpoint}?${new URLSearchParams({ ...report.value.filters, format: "xlsx" })}`);
    if (!response.ok || !response.headers.get("Content-Type")?.includes("spreadsheetml")) throw new Error("Export mislukt. Controleer of je nog bent ingelogd.");
    const url = URL.createObjectURL(await response.blob());
    const a = document.createElement("a"); a.href = url; a.download = `personeelsinzet-${report.value.filters.from}-${report.value.filters.to}.xlsx`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (e) { error.value = e instanceof Error ? e.message : "Export mislukt."; }
  finally { exporting.value = false; }
}
onMounted(load);
</script>

<template>
  <main class="staff-deployment">
    <header><h1>Personeelsinzet</h1><p>Ingeplande les- en kokuren per medewerker. Bekijk de verdeling over modules, scholen en aanvraagstatussen.</p></header>
    <form class="staff-deployment__filters" @submit.prevent="load">
      <label>Vanaf<input v-model="filters.from" type="date" required /></label>
      <label>Tot en met<input v-model="filters.to" type="date" required /></label>
      <AdminSelect v-model="filters.status" label="Aanvraagstatus" :allow-empty="false" :options="[{value:'all',label:'Alle statussen'},...['Definitief','In optie','Afgewezen','Zonder aanvraag'].map(value=>({value,label:value}))]" />
      <AdminSelect v-model="filters.roster" label="Rooster" :allow-empty="false" :options="[{value:'all',label:'Concept en definitief'},{value:'concept',label:'Concept'},{value:'published',label:'Definitief'}]" />
      <AdminSelect v-model="filters.staff" label="Medewerker" :allow-empty="false" :options="[{value:'all',label:'Alle medewerkers'},...(report?.staffOptions ?? []).map(s=>({value:String(s.id),label:s.name}))]" />
      <AdminSelect v-model="filters.employment" label="Type inzet" :allow-empty="false" :options="[{value:'all',label:'Betaald en vrijwillig'},{value:'paid',label:'Betaald'},{value:'volunteer',label:'Vrijwilliger'}]" />
      <button class="admin-button admin-button--primary" :disabled="busy">{{ busy ? 'Bezig met laden…' : 'Filters toepassen' }}</button>
    </form>
    <p v-if="error" role="alert" class="staff-deployment__error">{{ error }}</p>
    <StaffDeploymentInsights v-if="report" :data="report.insights" @select="selected = $event">
      <form class="staff-deployment__filters" @submit.prevent="load">
        <AdminSelect v-model="filters.costBasis" label="Kostenbasis" :allow-empty="false" :options="[{value:'lessons',label:'Alleen les- en kokuren'},{value:'span',label:'Eerste tot laatste les, inclusief pauzes'}]" />
        <label>Extra minuten per medewerkerdag<input v-model="filters.extraMinutes" type="number" min="0" max="240" required /></label>
        <button class="admin-button admin-button--primary" :disabled="busy">Scenario toepassen</button>
      </form>
      <p>Toegepast: {{ report.filters.costBasis === 'span' ? 'Eerste tot laatste les, inclusief pauzes' : 'Les- en kokuren' }}, plus {{ report.filters.extraMinutes }} minuten per medewerkerdag voor voorbereiding en afronding.</p>
    </StaffDeploymentInsights>
    <section v-if="report" :aria-busy="busy">
      <div class="staff-deployment__heading"><div><h2>{{ report.rows.length }} {{ report.rows.length === 1 ? 'medewerker' : 'medewerkers' }}</h2><p>{{ report.filters.from }} t/m {{ report.filters.to }} · Klik op een naam voor de moduleverdeling.</p></div><button class="admin-button" :disabled="busy || exporting" @click="download">{{ exporting ? 'Excel maken…' : 'Download Excel' }}</button></div>
      <p v-if="!report.rows.length">Geen ingeroosterde medewerkers gevonden voor deze filters.</p>
      <div v-else class="staff-deployment__scroll"><table><caption class="staff-deployment__caption">Uren op basis van de toegepaste filters</caption><thead><tr><th>Medewerker</th><th>Type</th><th>Totale inzet</th><th>Definitief</th><th>In optie</th><th>Afgewezen*</th><th>Zonder aanvraag</th><th>Scholen</th><th>Dagen</th></tr></thead><tbody><tr v-for="row in report.rows" :key="row.id" :class="{ 'is-selected': selected === row.id }"><th><button :aria-pressed="selected === row.id" @click="selected = row.id">{{ row.name }}</button></th><td>{{ employment(row.employment) }}</td><td><strong>{{ hours(row.minutes) }}</strong></td><td>{{ hours(row.statuses.Definitief ?? 0) }}</td><td>{{ hours(row.statuses['In optie'] ?? 0) }}</td><td>{{ hours(row.statuses.Afgewezen ?? 0) }}</td><td>{{ hours(row.statuses['Zonder aanvraag'] ?? 0) }}</td><td>{{ row.schools }}</td><td>{{ row.days }}</td></tr></tbody></table></div>
      <p class="staff-deployment__note">* Afgewezen aanvragen tellen niet mee in de totale inzet. Aanvraagstatus en roosterstatus zijn afzonderlijke filters.</p>
    </section>
    <section v-if="person">
      <h2>{{ person.name }} · moduleverdeling</h2>
      <p>Begeleiding: <strong>{{ hours(person.guideMinutes) }}</strong> · Kok: <strong>{{ hours(person.cookMinutes) }}</strong> · Conceptroosters: {{ hours(person.conceptMinutes) }} · Definitieve roosters: {{ hours(person.publishedMinutes) }}</p>
      <div class="staff-deployment__scroll"><table><thead><tr><th>Module</th><th>Rol</th><th>Inzet</th><th>Lesmomenten</th><th>Afgewezen</th></tr></thead><tbody><tr v-for="module in person.modules" :key="module.module + module.role"><th>{{ module.module }}</th><td>{{ module.role }}</td><td>{{ hours(module.minutes) }}</td><td>{{ module.lessons }}</td><td>{{ hours(module.rejectedMinutes) }}</td></tr></tbody></table></div>
      <details><summary>Bekijk de {{ entries.length }} onderliggende roosterregels</summary><div class="staff-deployment__scroll"><table><thead><tr><th>Datum</th><th>School</th><th>Tijd</th><th>Module / rol</th><th>Aanvraag</th><th>Rooster</th></tr></thead><tbody><tr v-for="(entry, index) in entries" :key="index"><td>{{ entry.date }}</td><td><RouterLink :to="`/roosters/${entry.planId}`">{{ entry.school }}</RouterLink></td><td>{{ entry.start }}–{{ entry.end }}</td><td>{{ entry.moduleLabel }} · {{ entry.role }}</td><td>{{ entry.status }}</td><td>{{ entry.rosterStatus === 'published' ? 'Definitief' : 'Concept' }}</td></tr></tbody></table></div></details>
    </section>
    <details class="staff-deployment__definitions"><summary>Hoe tellen we de uren?</summary><p>Dit zijn ingeplande uren, geen registratie van werkelijk gewerkte uren. Pauzes, aankomst en tussenuren tellen niet mee. Gedeelde lessen en overlappende inzet tellen per medewerker en dag één keer in het totaal.</p><p>Module-, rol- en statuskolommen kunnen overlappen en zijn daarom niet altijd optelbaar. Scholen tellen op basis van schoolnaam en plaats; dagen zijn unieke bezoekdatums. Alleen niet-afgewezen inzet telt mee in deze aantallen.</p><p>Definitieve roosters gebruiken de vastgelegde planning. Bij conceptroosters gebruiken we de huidige indeling. De aanvraagstatus en het onderscheid betaald/vrijwilliger zijn de huidige gegevens. Aanvragen zonder rooster leveren geen personeelsuren op.</p></details>
  </main>
</template>
