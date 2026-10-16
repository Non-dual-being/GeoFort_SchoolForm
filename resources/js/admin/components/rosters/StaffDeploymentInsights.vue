<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ChartConfiguration } from 'chart.js';
import { Clock3, CalendarDays, School } from 'lucide-vue-next';
import AnalyticsChart from '../analytics/AnalyticsChart.vue';
import StaffViewToggle from './StaffViewToggle.vue';
import { deploymentHours as hours, deploymentType, type DeploymentInsights } from '../../types/staffDeployment';
const props = defineProps<{ data: DeploymentInsights }>();
defineEmits<{ select: [id: number] }>();
const timeView = ref<'chart'|'table'>('chart'), moduleView = ref<'chart'|'table'>('chart');
const rankings = [{ key:'minutes', label:'Meeste les- en kokuren', icon:Clock3 }, { key:'days', label:'Meeste bezoekdagen', icon:CalendarDays }, { key:'schools', label:'Meeste scholen', icon:School }];
const groups = computed(() => props.data.groups.filter(g => g.staff > 0));
const chartOptions = (): ChartConfiguration<'bar'>['options'] => ({ responsive:true, maintainAspectRatio:false, animation:false, indexAxis:'y', plugins:{ legend:{position:'bottom',labels:{usePointStyle:true}}, tooltip:{callbacks:{label:context=>context.dataset.label+': '+hours(Number(context.raw)*60)}} }, scales:{x:{beginAtZero:true,stacked:true,title:{display:true,text:'Uren'}},y:{stacked:true,grid:{display:false}}} });
const timeChart = computed<ChartConfiguration<'bar'>>(() => ({ type:'bar', data:{ labels:groups.value.map(g=>g.label), datasets:[
  {label:'Les- en kokuren',data:groups.value.map(g=>g.minutes/60),backgroundColor:'#173e74',borderRadius:5},
  {label:'Tussenuren',data:groups.value.map(g=>g.gapMinutes/60),backgroundColor:'#5da6bd',borderRadius:5},
  {label:'Pauzetijd',data:groups.value.map(g=>g.breakMinutes/60),backgroundColor:'#c5d5e8',borderRadius:5},
] }, options:chartOptions() }));
const moduleLabels = computed(() => [...new Set(props.data.modules.map(m=>m.module))]);
const moduleChart = computed<ChartConfiguration<'bar'>>(() => ({ type:'bar', data:{labels:moduleLabels.value,datasets:groups.value.map(g=>({label:g.label,data:moduleLabels.value.map(label=>props.data.modules.find(m=>m.employment===g.key&&m.module===label)?.minutes ?? 0).map(m=>m/60),backgroundColor:g.key==='paid'?'#173e74':g.key==='volunteer'?'#5da6bd':'#a1b4ca',borderRadius:5}))},options:chartOptions() }));
</script>
<template>
  <section aria-label="Samenvatting personeelsinzet">
    <h2>Inzet in één oogopslag</h2>
    <div class="staff-insights__cards"><div><span>Les- en kokuren</span><strong>{{ hours(data.minutes) }}</strong></div><div><span>Ingezette medewerkers</span><strong>{{ data.staff }}</strong></div><div><span>Bezoekdagen</span><strong>{{ data.days }}</strong></div><div><span>Scholen</span><strong>{{ data.schools }}</strong></div></div>
    <div class="staff-insights__rankings">
      <article v-for="ranking in rankings" :key="ranking.key">
        <h3><component :is="ranking.icon" :size="18" aria-hidden="true" />{{ ranking.label }}</h3>
        <p v-if="!data.rankings[ranking.key]?.length">Geen inzet in deze selectie.</p>
        <ol><li v-for="(row,index) in data.rankings[ranking.key]" :key="row.id">
          <button class="staff-insights__person" :class="{'staff-insights__person--first':index===0}" :style="{'--fill':100*row.value/(data.rankings[ranking.key]?.[0]?.value||1)+'%'}" :aria-label="'Filter op '+row.name" @click="$emit('select',row.id)">
            <span class="staff-insights__rank">{{ index+1 }}</span><span class="staff-insights__name">{{ row.name }}</span><strong>{{ ranking.key==='minutes'?hours(row.value):row.value }}</strong>
          </button>
        </li></ol>
      </article>
    </div>
    <p class="staff-deployment__note">Klik op een medewerker om de hele analyse te filteren. Bij gelijke aantallen staan namen op alfabet.</p>
  </section>
  <section aria-label="Urenanalyse">
    <div class="staff-deployment__heading"><div><h2>Hoe is de tijd verdeeld?</h2><p>Alle tijden volgen de filters bovenaan. Opgeteld per medewerker en bezoekdag.</p></div><StaffViewToggle v-model="timeView" label="Weergave urenanalyse" /></div>
    <div class="staff-insights__cards staff-insights__cards--time"><div><span>Totale tijd</span><strong>{{ hours(data.spanMinutes) }}</strong><small>Eerste tot einde laatste les</small></div><div><span>Les- en kokuren</span><strong>{{ hours(data.minutes) }}</strong><small>Ingeplande begeleiding</small></div><div><span>Tussenuren</span><strong>{{ hours(data.gapMinutes) }}</strong><small>Vrij tijdens lesrondes</small></div><div><span>Pauzetijd</span><strong>{{ hours(data.breakMinutes) }}</strong><small>Geen les op betrokken scholen</small></div></div>
    <AnalyticsChart v-if="timeView==='chart'" :config="timeChart" label="Lesuren, tussenuren en pauzetijd per type medewerker" />
    <div v-else class="staff-deployment__scroll"><table><caption class="staff-deployment__caption">Tijdverdeling per type medewerker</caption><thead><tr><th>Type</th><th>Medewerkers</th><th>Totale tijd</th><th>Les- en kokuren</th><th>Tussenuren</th><th>Pauzetijd</th></tr></thead><tbody><tr v-for="g in groups" :key="g.key"><th>{{ g.label }}</th><td>{{ g.staff }}</td><td>{{ hours(g.spanMinutes) }}</td><td>{{ hours(g.minutes) }}</td><td>{{ hours(g.gapMinutes) }}</td><td>{{ hours(g.breakMinutes) }}</td></tr></tbody></table></div>
    <p class="staff-deployment__note">Totale tijd = les- en kokuren + tussenuren + pauzetijd. Dit is de planning, geen registratie van aanwezigheid. Tijd vóór de eerste en na de laatste les telt niet mee.</p>
    <details><summary>Bekijk de {{ data.daily.length }} medewerkerdagen</summary><div class="staff-deployment__scroll"><table><thead><tr><th>Medewerker</th><th>Datum</th><th>Type</th><th>Totale tijd</th><th>Les- en kokuren</th><th>Tussenuren</th><th>Pauzetijd</th></tr></thead><tbody><tr v-for="day in data.daily" :key="day.staffId+'-'+day.date"><th><button @click="$emit('select',day.staffId)">{{ day.name }}</button></th><td>{{ day.date }}</td><td>{{ deploymentType(day.employment) }}</td><td>{{ hours(day.spanMinutes) }}</td><td>{{ hours(day.minutes) }}</td><td>{{ hours(day.gapMinutes) }}</td><td>{{ hours(day.breakMinutes) }}</td></tr></tbody></table></div></details>
  </section>
  <section aria-label="Moduleverdeling">
    <div class="staff-deployment__heading"><div><h2>Uren per module</h2><p>Les- en kokuren van de geselecteerde medewerkers.</p></div><StaffViewToggle v-model="moduleView" label="Weergave moduleverdeling" /></div>
    <AnalyticsChart v-if="moduleView==='chart'" :config="moduleChart" label="Uren per module, betaald en vrijwillig" />
    <div v-else class="staff-deployment__scroll"><table><caption class="staff-deployment__caption">Moduleverdeling binnen de selectie</caption><thead><tr><th>Module</th><th>Type medewerker</th><th>Les- en kokuren</th></tr></thead><tbody><tr v-for="module in data.modules" :key="module.module+module.employment"><th>{{ module.module }}</th><td>{{ deploymentType(module.employment) }}</td><td>{{ hours(module.minutes) }}</td></tr></tbody></table></div>
    <p class="staff-deployment__note">Gedeelde lessen tellen per medewerker één keer. Inzet op verschillende modules tegelijk kan per module meetellen; het urentotaal wordt ontdubbeld.</p>
  </section>
</template>
