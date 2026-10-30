<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from "vue";
import { RouterLink } from "vue-router";
import type { ChartConfiguration } from "chart.js";
import AnalyticsChart from "../components/analytics/AnalyticsChart.vue";
import { ratings, type Satisfaction } from "../../survey/policy";

type Settings = { enabled: boolean; delayDays: number; startDate: string | null; revision: number };
type Visit = { bookingId: number; school: string; visitDate: string; sector: string; variant: string; state: string; excluded: boolean; dueDate: string; sentAt: string | null; satisfaction: Satisfaction | null; tip: string | null };
type Report = { settings: Settings; invited: number; responses: number; responseRate: number | null; distribution: Record<Satisfaction, number>; months: (Record<Satisfaction, number> & { month: string })[]; modules: { key: string; label: string; exposed: number; favorites: number; percentage: number }[]; visits: Visit[] };
const report = ref<Report | null>(null);
const filters = ref({ from: "", to: "", sector: "all", variant: "all" });
const settings = ref<Settings>({ enabled: false, delayDays: 2, startDate: null, revision: 1 });
const busy = ref(false); const error = ref(""); const message = ref(""); const local = ref(false);
let csrf = ""; let requestId = 0;
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(noticeTimer));
const sectors: Record<string,string> = { all: "Alle scholen", po: "Primair onderwijs", so: "Speciaal onderwijs", "vo-lower": "VO onderbouw", "vo-upper": "VO bovenbouw" };
const variants: Record<string,string> = { all: "Alle programma’s", "day-four": "Dag · vier modules", "day-five": "Dag · vijf modules", morning: "Ochtend", custom: "Maatwerk", unknown: "Onbekend" };
const states: Record<string,string> = { ready: "Klaar voor verzending", scheduled: "Ingepland", paused: "Verzending staat uit", "before-start": "Voor de startdatum", "not-confirmed": "Aanvraag niet definitief", "contact-changed": "Contactadres gewijzigd · toestemming nodig", "missing-modules": "Geen lessen opgeslagen · controleren", excluded: "Overgeslagen door planner", test: "Testaanvraag · overgeslagen", sending: "Verzending controleren", sent: "Verstuurd", answered: "Beantwoord", failed: "Mail mislukt · handmatig controleren", withdrawn: "Toestemming ingetrokken" };
const day = (date: string) => new Date(date.slice(0,10) + "T12:00:00").toLocaleDateString("nl-NL", { day: "numeric", month: "long", year: "numeric" });
const tips = computed(() => report.value?.visits.filter(v => v.tip) ?? []);
const chart = computed<ChartConfiguration>(() => ({ type: "bar", data: {
  labels: report.value?.months.map(m => new Date(m.month + "-01T12:00:00").toLocaleDateString("nl-NL", { month: "short", year: "numeric" })) ?? [],
  datasets: ratings.map((rating, index) => ({ label: rating.label, data: report.value?.months.map(m => m[rating.value]) ?? [], backgroundColor: ["#456787", "#a0afbf", "#70a99b"][index] })),
}, options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: "Antwoorden" } } } } }));
async function load(change?: Record<string, unknown>) {
  const id = ++requestId; busy.value = true; error.value = ""; message.value = "";
  try {
    const response = await fetch("/api/admin/visit-surveys.php?" + new URLSearchParams(filters.value), {
      credentials: "same-origin", ...(change ? { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(change) } : {}),
    });
    const result = await response.json();
    if (id !== requestId) return;
    if (!response.ok || !result.ok) throw new Error(result.message ?? "Evaluaties konden niet worden geladen.");
    report.value = result.data; settings.value = { ...result.data.settings }; csrf = result.csrfToken; local.value = result.local;
    if (change) {
      message.value = change.action === "settings" ? "Instellingen opgeslagen." : "Dit bezoek is uitgesloten van de evaluatie.";
      clearTimeout(noticeTimer); noticeTimer = setTimeout(() => { message.value = ""; }, 5000);
    }
  } catch (e) { if (id === requestId) error.value = e instanceof Error ? e.message : "Er ging iets mis."; }
  finally { if (id === requestId) busy.value = false; }
}
async function exclude(visit: Visit) {
  if (window.confirm(`Geen bezoekevaluatie voor ${visit.school}? Gebruik dit bijvoorbeeld bij een test of een bezoek dat niet is doorgegaan. Een al verstuurde mail kan niet worden teruggehaald; de evaluatielink wordt wel gesloten.`)) {
    await load({ action: "exclude", bookingId: visit.bookingId });
  }
}
onMounted(() => load());
</script>

<template>
  <div class="survey-dashboard">
    <header><p class="eyebrow">ONDERWIJSKWALITEIT</p><h1>Bezoekevaluaties</h1><p>Hoe scholen hun bezoek ervaren en wat we kunnen verbeteren.</p></header>
    <p v-if="local" class="info">Lokale testomgeving. Hier worden geen automatische evaluatiemails verstuurd.</p>
    <p v-if="error" role="alert" class="error">{{ error }}</p><p v-if="message" role="status" class="info">{{ message }}</p>
    <details class="card settings">
      <summary>Uitnodigingen instellen <span>{{ report?.settings.enabled ? 'Ingeschakeld' : 'Uitgeschakeld' }}</span></summary>
      <form @submit.prevent="load({ action: 'settings', ...settings, startDate: settings.startDate || null })">
        <label class="check"><input v-model="settings.enabled" type="checkbox" :disabled="busy"> Automatisch uitnodigen na een bezoek</label>
        <div class="filters"><label>Aantal kalenderdagen na bezoek<input v-model.number="settings.delayDays" type="number" min="1" max="30" required :disabled="busy"></label><label>Voor bezoeken vanaf<input v-model="settings.startDate" type="date" :required="settings.enabled" :disabled="busy"></label></div>
        <p>Alleen definitieve aanvragen met uitdrukkelijke toestemming komen in aanmerking. De opgeslagen lessen bepalen de modulevraag. Testaanvragen en uitgesloten bezoeken worden overgeslagen. Elke school krijgt hoogstens één uitnodiging; er zijn geen herinneringsmails.</p>
        <p>Markeer bezoeken die niet zijn doorgegaan hieronder als ‘Overslaan’ voordat de verzenddatum aanbreekt. Een gewijzigde bezoekdatum verschuift de geplande uitnodiging mee.</p>
        <button :disabled="busy">Instellingen opslaan</button>
      </form>
    </details>
    <form class="card filters" @submit.prevent="load()">
      <label>Bezoekdatum vanaf<input v-model="filters.from" type="date"></label><label>Tot en met<input v-model="filters.to" type="date"></label>
      <label>Onderwijs<select v-model="filters.sector"><option v-for="(label,key) in sectors" :key="key" :value="key">{{ label }}</option></select></label>
      <label>Programma<select v-model="filters.variant"><option v-for="(label,key) in variants" :key="key" :value="key">{{ label }}</option></select></label>
      <button :disabled="busy">Toon resultaten</button>
    </form>
    <p v-if="busy" role="status">Evaluaties laden…</p>
    <template v-if="report">
      <div class="metrics"><article class="card"><span>Uitnodigingen verstuurd</span><strong>{{ report.invited }}</strong></article><article class="card"><span>Antwoorden</span><strong>{{ report.responses }}</strong></article><article class="card"><span>Respons</span><strong>{{ report.responseRate === null ? '—' : report.responseRate.toLocaleString('nl-NL') + '%' }}</strong></article></div>
      <p class="muted">Eén antwoord per schoolbezoek, namens de school. Gekoppelde scholen tellen ieder afzonderlijk. Aantallen leerlingen wegen niet mee.</p>
      <div class="results-grid">
        <section class="card"><h2>Ervaring</h2><p v-if="!report.responses">Er zijn nog geen antwoorden binnen deze selectie.</p><div v-for="rating in ratings" :key="rating.value" class="rating-row"><span>{{ rating.label }}</span><strong>{{ report.distribution[rating.value] }}</strong><span>{{ report.responses ? Math.round(100 * report.distribution[rating.value] / report.responses) + '%' : '—' }}</span></div><p class="muted">We tonen de verdeling; er wordt geen rapportcijfer van gemaakt.</p></section>
        <section class="card"><h2>Ervaring per bezoekmaand</h2><AnalyticsChart :config="chart" label="Antwoorden per bezoekmaand en ervaring" empty-message="Nog geen antwoorden voor deze periode." /><details v-if="report.months.length"><summary>Bekijk de aantallen</summary><div class="table-scroll"><table><thead><tr><th>Bezoekmaand</th><th v-for="rating in ratings" :key="rating.value">{{ rating.label }}</th></tr></thead><tbody><tr v-for="month in report.months" :key="month.month"><td>{{ month.month }}</td><td v-for="rating in ratings" :key="rating.value">{{ month[rating.value] }}</td></tr></tbody></table></div></details></section>
      </div>
      <section class="card"><h2>Favoriete modules</h2><p class="muted">Hoe vaak een module favoriet is bij respondenten die deze module in hun rooster hadden. Zo vergelijken we modules ook wanneer ze niet even vaak zijn gevolgd.</p><p v-if="!report.modules.length">Nog geen modulevoorkeuren beschikbaar.</p><div v-else class="table-scroll"><table><thead><tr><th>Module</th><th>Gevolgd door respondenten</th><th>Favoriet</th><th>Aandeel</th></tr></thead><tbody><tr v-for="module in report.modules" :key="module.key"><td>{{ module.label }}</td><td>{{ module.exposed }}</td><td>{{ module.favorites }}</td><td>{{ module.percentage.toLocaleString('nl-NL') }}%</td></tr></tbody></table></div></section>
      <section class="card"><h2>Tips van scholen</h2><p v-if="!tips.length">Er zijn nog geen tips binnen deze selectie.</p><div class="tips"><article v-for="visit in tips" :key="visit.bookingId"><p class="tip">{{ visit.tip }}</p><small>{{ visit.school }} · {{ day(visit.visitDate) }}</small></article></div></section>
      <section class="card"><h2>Uitnodigingen en toestemming</h2><p v-if="!report.visits.length">Nog geen aanvragen met toestemming binnen deze selectie. Bestaande aanvragen krijgen niet automatisch toestemming.</p><div v-else class="table-scroll"><table><thead><tr><th>School en bezoek</th><th>Programma</th><th>Verzending vanaf</th><th>Status</th><th>Actie</th></tr></thead><tbody><tr v-for="visit in report.visits" :key="visit.bookingId"><td><RouterLink :to="'/aanvragen/' + visit.bookingId">{{ visit.school }}</RouterLink><small>{{ day(visit.visitDate) }}</small></td><td>{{ variants[visit.variant] }}</td><td>{{ day(visit.dueDate) }}</td><td>{{ states[visit.state] ?? visit.state }}<small v-if="visit.excluded && visit.state !== 'excluded'">Uitgesloten door planner</small></td><td><button v-if="!visit.excluded && !['withdrawn','answered','test'].includes(visit.state)" :disabled="busy" @click="exclude(visit)">Overslaan</button></td></tr></tbody></table></div></section>
    </template>
  </div>
</template>

<style scoped>
.survey-dashboard { display: grid; gap: 1.3rem; color: #102454; }.survey-dashboard h1 { margin: .2rem 0 .6rem; }.survey-dashboard h2 { font-size: 1.2rem; margin-top: 0; }.eyebrow { font-size: .75rem; letter-spacing: .09em; }
.survey-dashboard p { margin: .7rem 0; line-height: 1.6; }
.card { background: white; border: 1px solid #cdd9e7; border-radius: .8rem; padding: 1.4rem; min-width: 0; }.info,.error { padding: 1rem; border-radius: .6rem; margin: 0; }.info { background: #eff5ff; border: 1px solid #b5c8e6; }.error { background: #fff0f3; color: #9d1730; }
.filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; }.filters label { display: grid; gap: .4rem; flex: 1 1 150px; font-weight: 600; }.filters input,.filters select { box-sizing: border-box; width: 100%; font: inherit; background: white; color: inherit; padding: .65rem; border: 1px solid #b5c8df; border-radius: .5rem; }
button { font: inherit; background: #123467; color: white; border: 0; border-radius: .5rem; padding: .75rem 1rem; cursor: pointer; }button:disabled { opacity: .55; cursor: default; }
summary { cursor: pointer; font-weight: 700; }summary span { float: right; font-size: .8rem; color: #50627c; }.settings form { margin-top: 1.3rem; }.check { display: flex; gap: .7rem; align-items: center; margin: 1rem 0; }.check input { width: 1.1rem; height: 1.1rem; accent-color: #123467; }
.metrics { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 1rem; }.metrics strong { display: block; font-size: 2rem; margin-top: .3rem; }.muted { font-size: .9rem; color: #53647c; line-height: 1.6; }.results-grid { display: grid; grid-template-columns: 1fr 1.7fr; gap: 1rem; }.rating-row { display: grid; grid-template-columns: 1fr 2rem 3rem; gap: .75rem; border-bottom: 1px solid #e5ebf3; padding: .8rem 0; }.rating-row>span:last-child { text-align: right; }
.table-scroll { overflow-x: auto; }table { border-collapse: collapse; width: 100%; text-align: left; }th,td { border-bottom: 1px solid #e0e8f1; padding: .85rem; }th { background: #f2f6fb; }td small { display: block; margin-top: .4rem; }a { color: #164f8b; }
.tips { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(300px,100%),1fr)); gap: 1rem; }.tips article { background: #f5f8fc; padding: 1rem; border-radius: .6rem; }.tip { white-space: pre-wrap; overflow-wrap: anywhere; }.tips small { color: #53647c; }
@media (max-width: 800px) { .results-grid { grid-template-columns: 1fr; }.metrics { gap: .5rem; }.metrics .card { padding: .9rem; }.metrics span { font-size: .8rem; } }
</style>
