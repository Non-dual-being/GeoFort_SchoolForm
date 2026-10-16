<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import type { ChartConfiguration } from 'chart.js';
import StaffDeploymentInsights from "../components/rosters/StaffDeploymentInsights.vue";
import StaffViewToggle from "../components/rosters/StaffViewToggle.vue";
import AnalyticsChart from "../components/analytics/AnalyticsChart.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import { getApiData } from "../../services/http/apiClient";
import { deploymentHours as hours, deploymentType as employment, type DeploymentFilters, type DeploymentReport } from '../types/staffDeployment';
const defaults = (): DeploymentFilters => ({ from:'', to:'', year:'all', range:'auto', status:'all', roster:'all', staff:'all', employment:'all' });
const filters = ref<DeploymentFilters>(defaults());
const report = ref<DeploymentReport>();
const busy = ref(false), exporting = ref(false), error = ref('');
const staffView = ref<'chart'|'table'>('table');
const person = computed(() => report.value?.rows.length === 1 ? report.value.rows[0] : undefined);
const endpoint = "/api/admin/rosters/staff-deployment.php";
const dateLabel = (date: string) => date ? new Date(date+'T12:00:00').toLocaleDateString('nl-NL',{day:'numeric',month:'long',year:'numeric'}) : '';
const period = computed(() => report.value?.filters.from ? dateLabel(report.value.filters.from)+' t/m '+dateLabel(report.value.filters.to) : 'Geen roosters binnen deze selectie');
const labels: Record<string,string> = { all:'Alle', paid:'Betaald', volunteer:'Vrijwilliger', unknown:'Onbekend', concept:'Concept', published:'Definitief' };
function options(key: string, allLabel: string, universe?: string[]) {
  const available = report.value?.options[key] ?? [];
  const values = universe?.map(value=>({value,label:labels[value]??value})) ?? available;
  return [{value:'all',label:allLabel}, ...values.map(value=>({...value,disabled:!available.some(a=>a.value===value.value),description:available.some(a=>a.value===value.value)?undefined:'Geen roosters bij de overige filters'}))];
}
let generation = 0;
async function load() {
  const request = ++generation;
  busy.value = true; error.value = '';
  try {
    const result = await getApiData<DeploymentReport>(endpoint+'?'+new URLSearchParams(filters.value));
    if (request !== generation) return;
    report.value = result; filters.value = {...result.filters};
  } catch (e) { if (request===generation) error.value = e instanceof Error ? e.message : 'Laden mislukt.'; }
  finally { if(request===generation) busy.value = false; }
}
function change(key: keyof DeploymentFilters, value: string) {
  filters.value = {...filters.value,[key]:value,range:'auto',from:'',to:''};
  void load();
}
function dateChange(key: 'from'|'to', value: string) {
  if(!value) return;
  filters.value = {...filters.value,[key]:value,range:'custom'};
  if(filters.value.from > filters.value.to) filters.value[key==='from'?'to':'from']=value;
  void load();
}
function reset() { filters.value = defaults(); void load(); }
async function download() {
  if(!report.value) return;
  exporting.value=true; error.value='';
  try {
    const response=await fetch(endpoint+'?'+new URLSearchParams({...report.value.filters,format:'xlsx'}));
    if(!response.ok||!response.headers.get('Content-Type')?.includes('spreadsheetml')) throw new Error('Export mislukt. Controleer of je nog bent ingelogd.');
    const url=URL.createObjectURL(await response.blob()), a=document.createElement('a');
    a.href=url;a.download='personeelsinzet-'+report.value.filters.from+'-'+report.value.filters.to+'.xlsx';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  } catch(e) {error.value=e instanceof Error?e.message:'Export mislukt.';} finally {exporting.value=false;}
}
const staffChart = computed<ChartConfiguration<'bar'>>(()=>({ type:'bar',data:{labels:report.value?.rows.map(r=>r.name)??[],datasets:[{label:'Les- en kokuren',data:report.value?.rows.map(r=>r.minutes/60)??[],backgroundColor:report.value?.rows.map(r=>r.employment==='volunteer'?'#5da6bd':'#173e74'),borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,animation:false,indexAxis:'y',plugins:{legend:{display:false},tooltip:{callbacks:{label:context=>hours(Number(context.raw)*60)}}},scales:{x:{beginAtZero:true,title:{display:true,text:'Uren'}},y:{grid:{display:false}}}}}));
onMounted(load);
</script>
<template>
  <main class="staff-deployment">
    <header class="staff-deployment__heading"><div><h1>Personeelsinzet</h1><p>Van lesuren tot tussenuren. Bekijk de ingeplande tijd per medewerker, module en school.</p></div><button class="admin-button" :disabled="busy||exporting||!report?.rows.length" @click="download">{{ exporting?'Excel maken…':'Download Excel' }}</button></header>
    <div class="staff-deployment__filter-panel">
      <div class="staff-deployment__heading"><h2>Jouw selectie</h2><button class="staff-deployment__reset" :disabled="busy" @click="reset">Alle filters wissen</button></div>
      <div class="staff-deployment__filters">
        <AdminSelect :model-value="filters.year" label="Jaar" :disabled="busy" :allow-empty="false" :options="options('year','Alle jaren')" @update:model-value="change('year',$event)" />
        <AdminSelect :model-value="filters.status" label="Aanvraagstatus" :disabled="busy" :allow-empty="false" :options="options('status','Alle statussen',['Definitief','In optie','Afgewezen','Zonder aanvraag'])" @update:model-value="change('status',$event)" />
        <AdminSelect :model-value="filters.employment" label="Type inzet" :disabled="busy" :allow-empty="false" :options="options('employment','Betaald en vrijwillig',['paid','volunteer','unknown'])" @update:model-value="change('employment',$event)" />
        <AdminSelect :model-value="filters.staff" label="Medewerker" :disabled="busy" :allow-empty="false" :options="options('staff','Alle medewerkers')" @update:model-value="change('staff',$event)" />
        <AdminSelect :model-value="filters.roster" label="Roosterstatus" :disabled="busy" :allow-empty="false" :options="options('roster','Concept en definitief',['concept','published'])" @update:model-value="change('roster',$event)" />
        <AdminDateField :model-value="filters.from" label="Vanaf" :disabled="busy||!report?.dateBounds.min" :min="report?.dateBounds.min??undefined" :max="report?.dateBounds.max??undefined" @update:model-value="dateChange('from',$event)" />
        <AdminDateField :model-value="filters.to" label="Tot en met" :disabled="busy||!report?.dateBounds.min" :min="report?.dateBounds.min??undefined" :max="report?.dateBounds.max??undefined" @update:model-value="dateChange('to',$event)" />
      </div>
      <p class="staff-deployment__note">Filters werken direct door in alle kaarten, grafieken, lijsten en de Excel-export. Bij een andere selectie volgt de periode automatisch de passende roosters. Datums buiten die periode zijn niet beschikbaar.</p>
      <div v-if="report" class="staff-deployment__selection" role="status"><span>{{ filters.range==='auto'?'Automatische periode':'Eigen periode' }}</span><strong>{{ period }}</strong><button v-if="filters.range==='custom'" :disabled="busy" @click="change('range','auto')">Hele beschikbare periode</button></div>
    </div>
    <p v-if="error" role="alert" class="staff-deployment__error">{{ error }} <button class="admin-button" @click="load">Opnieuw proberen</button></p>
    <p v-if="busy" role="status" class="staff-deployment__loading">De analyse wordt bijgewerkt…</p>
    <div v-if="report" v-show="!busy&&!error" :aria-busy="busy">
      <p v-if="report.filters.status==='Afgewezen'" class="staff-deployment__notice">Je bekijkt de oorspronkelijke planning van afgewezen aanvragen. Deze uren zijn geen uitgevoerde inzet.</p>
      <p v-else class="staff-deployment__note">Afgewezen aanvragen tellen alleen mee als je die status kiest. Alle cijfers gaan over geplande inzet, niet over geregistreerde gewerkte uren.</p>
      <section v-if="!report.rows.length" class="staff-deployment__empty"><h2>Geen inzet in deze selectie</h2><p>Kies een andere status, periode of medewerker om roosters te vergelijken.</p><button class="admin-button" @click="reset">Toon alle beschikbare inzet</button></section>
      <template v-else>
        <StaffDeploymentInsights :data="report.insights" @select="change('staff',String($event))" />
        <section aria-label="Medewerkers">
          <div class="staff-deployment__heading"><div><h2>{{ report.rows.length }} {{ report.rows.length===1?'medewerker':'medewerkers' }}</h2><p>{{ period }}</p></div><StaffViewToggle v-model="staffView" label="Weergave medewerkers" /></div>
          <AnalyticsChart v-if="staffView==='chart'" :config="staffChart" label="Les- en kokuren per medewerker" @select="(_dataset,index)=>change('staff',String(report!.rows[index]!.id))" />
          <div v-else class="staff-deployment__scroll"><table><caption class="staff-deployment__caption">Les- en kokuren binnen jouw selectie</caption><thead><tr><th>Medewerker</th><th>Type</th><th>Les- en kokuren</th><th>Definitief</th><th>In optie</th><th>Afgewezen</th><th>Zonder aanvraag</th><th>Scholen</th><th>Dagen</th></tr></thead><tbody><tr v-for="row in report.rows" :key="row.id"><th><button @click="change('staff',String(row.id))">{{ row.name }}</button></th><td>{{ employment(row.employment) }}</td><td><strong>{{ hours(row.minutes) }}</strong></td><td>{{ hours(row.statuses.Definitief??0) }}</td><td>{{ hours(row.statuses['In optie']??0) }}</td><td>{{ hours(row.statuses.Afgewezen??0) }}</td><td>{{ hours(row.statuses['Zonder aanvraag']??0) }}</td><td>{{ row.schools }}</td><td>{{ row.days }}</td></tr></tbody></table></div>
        </section>
        <section v-if="person">
          <h2>{{ person.name }} · moduleverdeling</h2>
          <p>Begeleiding: <strong>{{ hours(person.guideMinutes) }}</strong> · Kok: <strong>{{ hours(person.cookMinutes) }}</strong> · Conceptroosters: {{ hours(person.conceptMinutes) }} · Definitieve roosters: {{ hours(person.publishedMinutes) }}</p>
          <div class="staff-deployment__scroll"><table><thead><tr><th>Module</th><th>Rol</th><th>Les- en kokuren</th><th>Lesmomenten</th></tr></thead><tbody><tr v-for="module in person.modules" :key="module.module+module.role"><th>{{ module.module }}</th><td>{{ module.role }}</td><td>{{ hours(module.minutes) }}</td><td>{{ module.lessons }}</td></tr></tbody></table></div>
        </section>
        <details class="staff-deployment__definitions"><summary>Bekijk de {{ report.entries.length }} onderliggende roosterregels</summary><div class="staff-deployment__scroll"><table><thead><tr><th>Medewerker</th><th>Datum</th><th>School</th><th>Tijd</th><th>Module / rol</th><th>Aanvraag</th><th>Rooster</th></tr></thead><tbody><tr v-for="(entry,index) in report.entries" :key="index"><th>{{ entry.name }}</th><td>{{ entry.date }}</td><td><RouterLink :to="'/roosters/'+entry.planId">{{ entry.school }}</RouterLink></td><td>{{ entry.start }}–{{ entry.end }}</td><td>{{ entry.moduleLabel }} · {{ entry.role }}</td><td>{{ entry.status }}</td><td>{{ entry.rosterStatus==='published'?'Definitief':'Concept' }}</td></tr></tbody></table></div></details>
      </template>
      <details class="staff-deployment__definitions"><summary>Hoe tellen we de uren?</summary><p>Les- en kokuren zijn de ingeplande begeleiding. Totale tijd loopt per medewerker en dag van de eerste tot het einde van de laatste les. Tussenuren zijn vrije momenten binnen die tijd waarop een betrokken school wel lessen heeft. De overige tijd is pauzetijd.</p><p>Overlappende inzet telt per medewerker en dag één keer in het totaal. Module-, rol- en statuskolommen kunnen overlappen en zijn daarom niet altijd optelbaar. Scholen tellen op basis van naam en plaats; bezoekdagen zijn unieke datums.</p><p>Definitieve roosters gebruiken hun vastgelegde planning. Concepten gebruiken de huidige indeling. Aanvraagstatus en betaald/vrijwilliger volgen de huidige gegevens. Een aanvraag zonder ingeroosterde medewerker levert geen uren op.</p></details>
    </div>
  </main>
</template>
