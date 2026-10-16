<script setup lang="ts">
export interface DeploymentInsights {
  modules: {employment:string;module:string;minutes:number}[];
  minutes: number; staff: number; days: number; schools: number;
  rankings: Record<string, {id:number;name:string;value:number}[]>;
  groups: {key:string;label:string;staff:number;minutes:number;staffDays:number;averageMinutes:number;shortVisits:number;gapMinutes:number}[];
  daily: {staffId:number;name:string;employment:string;date:string;minutes:number;gapMinutes:number;shortVisit:boolean;costMinutes:number;costCents:number|null}[];
  finance: {knownCostCents:number;unknownRateDays:number;scopeComplete:boolean;balanceCents:number|null;revenue:{confirmedCents:number;optionCents:number;missing:number;unlinked:number;count:number}};
}
defineProps<{data:DeploymentInsights}>();
defineEmits<{select:[id:number]}>();
const hours=(m:number)=>new Intl.NumberFormat('nl-NL',{maximumFractionDigits:1}).format(m/60)+' u';
const money=(c:number)=>new Intl.NumberFormat('nl-NL',{style:'currency',currency:'EUR'}).format(c/100);
const rankings=[{key:'minutes',label:'Meeste ingeplande uren'},{key:'days',label:'Meeste bezoekdagen'},{key:'schools',label:'Meeste scholen'}];
</script>
<template>
  <section aria-label="Samenvatting personeelsinzet">
    <h2>Inzet in één oogopslag</h2>
    <div class="staff-insights__cards"><div><span>Ingepland</span><strong>{{ hours(data.minutes) }}</strong></div><div><span>Ingezette medewerkers</span><strong>{{ data.staff }}</strong></div><div><span>Bezoekdagen</span><strong>{{ data.days }}</strong></div><div><span>Scholen</span><strong>{{ data.schools }}</strong></div></div>
    <div class="staff-insights__rankings"><div v-for="ranking in rankings" :key="ranking.key"><h3>{{ ranking.label }}</h3><p v-if="!data.rankings[ranking.key]?.length">Geen inzet in deze selectie.</p><ol><li v-for="row in data.rankings[ranking.key]" :key="row.id"><button @click="$emit('select',row.id)">{{ row.name }} <strong>{{ ranking.key==='minutes'?hours(row.value):row.value }}</strong></button><div class="staff-insights__bar" aria-hidden="true"><span :style="{width:`${100*row.value/(data.rankings[ranking.key]?.[0]?.value || 1)}%`}" /></div></li></ol></div></div>
  </section>
  <section>
    <h2>Betaald en vrijwillig</h2><p>Vergelijk de inzet per ingezette medewerker. Een medewerkerdag is één medewerker op één bezoekdatum.</p>
    <div class="staff-deployment__scroll"><table><thead><tr><th>Type</th><th>Medewerkers</th><th>Uren / aandeel</th><th>Gemiddeld per medewerker</th><th>Medewerkerdagen</th><th>Korte inzet ≤45 min</th><th>Vrije lesminuten ertussen</th></tr></thead><tbody><tr v-for="group in data.groups.filter(g=>g.staff>0)" :key="group.key"><th>{{ group.label }}</th><td>{{ group.staff }}</td><td>{{ hours(group.minutes) }} · {{ data.minutes?Math.round(group.minutes/data.minutes*100):0 }}%</td><td>{{ hours(group.averageMinutes) }}</td><td>{{ group.staffDays }}</td><td>{{ group.shortVisits }}</td><td>{{ group.gapMinutes }} min</td></tr></tbody></table></div>
    <p class="staff-deployment__note">Vrije lesminuten zijn momenten tussen de eigen lessen waarop een betrokken school wel lessen heeft. Pauzes zonder lessen tellen niet mee. Dit is een planningssignaal, geen beoordeling van medewerkers.</p>
    <details><summary>Moduleverdeling per type medewerker</summary><div class="staff-deployment__scroll"><table><thead><tr><th>Module</th><th>Type medewerker</th><th>Uren</th></tr></thead><tbody><tr v-for="module in data.modules" :key="module.module+module.employment"><th>{{ module.module }}</th><td>{{ module.employment==='paid'?'Betaald':module.employment==='volunteer'?'Vrijwilliger':'Onbekend' }}</td><td>{{ hours(module.minutes) }}</td></tr></tbody></table></div></details>
    <details><summary>Korte inzet en tussenuren bekijken</summary><div class="staff-deployment__scroll"><table><thead><tr><th>Medewerker</th><th>Datum</th><th>Inzet</th><th>Vrije lesminuten</th></tr></thead><tbody><tr v-for="day in data.daily.filter(d=>d.shortVisit||d.gapMinutes>0)" :key="day.staffId+day.date"><th><button @click="$emit('select',day.staffId)">{{ day.name }}</button></th><td>{{ day.date }}</td><td>{{ hours(day.minutes) }}</td><td>{{ day.gapMinutes }} min</td></tr></tbody></table></div></details>
  </section>
  <section>
    <h2>Omzet en personeelskosten · scenario</h2>
    <p>Omzet van de aanvragen bij de geselecteerde roosters, eenmaal per aanvraag en exclusief btw. Inclusief eventuele cateringomzet. Geen boekhoudkundige winst: overige kosten en werkelijk gewerkte uren ontbreken.</p>
    <div class="staff-insights__cards"><div><span>Definitieve aanvragen</span><strong>{{ money(data.finance.revenue.confirmedCents) }}</strong></div><div><span>In optie</span><strong>{{ money(data.finance.revenue.optionCents) }}</strong></div><div><span>Bekende personeelskosten</span><strong>{{ money(data.finance.knownCostCents) }}</strong></div><div><span>Omzet − personeelskosten</span><strong>{{ data.finance.balanceCents===null?'Onvolledig':money(data.finance.balanceCents) }}</strong></div></div>
    <p v-if="data.finance.unknownRateDays || data.finance.revenue.missing || data.finance.revenue.unlinked" role="status">Ontbrekend: {{ data.finance.unknownRateDays }} medewerkerdagen zonder eenduidig tarief, {{ data.finance.revenue.missing }} aanvragen zonder complete prijs en {{ data.finance.revenue.unlinked }} roosters zonder aanvraag. Ontbrekende kosten zijn niet €0.</p>
    <p v-if="!data.finance.scopeComplete">Bij een medewerker- of typefilter tonen we diens kosten, maar verdelen we de schoolomzet niet over personen. Het saldo blijft daarom verborgen.</p>
    <p class="staff-deployment__note">Tarieven komen uit Personeel en gelden op de bezoekdatum, ook voor vrijwilligersvergoedingen. Vul een expliciet nultarief in als er geen kosten zijn. Inzet zonder toegewezen medewerker kan nog extra kosten opleveren. De aannames hieronder veranderen geen roosters of personeelsafspraken.</p>
    <slot />
  </section>
</template>
