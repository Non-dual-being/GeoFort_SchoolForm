<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import AdminButton from "../components/form/AdminButton.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import RevenueBookingsTable from "../components/revenue/RevenueBookingsTable.vue";
import RevenueBreakdown from "../components/revenue/RevenueBreakdown.vue";
import { fetchBookingRevenueReport } from "../services/dashboardBookingRevenueApi";
import type { BookingRevenueReport } from "../types/bookingRevenue";
import { formatEuroCents } from "../utils/money";

const now = new Date();
const year = now.getFullYear(); const month = String(now.getMonth()+1).padStart(2,"0");
const startDate = ref(`${year}-${month}-01`);
const endDate = ref(`${year}-${month}-${String(new Date(year, now.getMonth()+1, 0).getDate()).padStart(2,"0")}`);
const report = ref<BookingRevenueReport|null>(null); const loading=ref(false); const error=ref(false);
let controller: AbortController|undefined;
const periodError = computed(() => startDate.value && endDate.value && startDate.value > endDate.value ? "De einddatum mag niet voor de begindatum liggen." : "");
const periodLabel = computed(() => `${dateLabel(report.value?.period.startDate ?? startDate.value)} t/m ${dateLabel(report.value?.period.endDate ?? endDate.value)}`);
const cards = computed(() => report.value ? [
  ["Definitieve omzet incl. btw",formatEuroCents(report.value.definitiveRevenue.totalInclVatCents)], ["Potentiële omzet incl. btw",formatEuroCents(report.value.potentialRevenue.totalInclVatCents)], ["Definitieve omzet excl. btw",formatEuroCents(report.value.definitiveRevenue.totalExclVatCents)], ["Btw definitieve omzet",formatEuroCents(report.value.definitiveRevenue.vatCents)],
  ["Boekingen",report.value.counts.bookingsTotal], ["Definitief",report.value.counts.definitive], ["In optie",report.value.counts.option], ["Afgewezen",report.value.counts.rejected], ["Prijs ontbreekt",report.value.counts.missingPrice], ["Leerlingen",report.value.counts.studentsTotal], ["Leerlingen PO",report.value.counts.studentsPrimary], ["Leerlingen VO",report.value.counts.studentsSecondary],
] : []);
function dateLabel(value:string):string { const [y,m,d]=value.split("-"); return y&&m&&d?`${d}-${m}-${y}`:value; }
async function load():Promise<void>{ if(!startDate.value||!endDate.value||periodError.value)return; controller?.abort(); controller=new AbortController(); loading.value=true; error.value=false; try{report.value=await fetchBookingRevenueReport(startDate.value,endDate.value,controller.signal)}catch(e){if(e instanceof DOMException&&e.name==="AbortError")return; report.value=null; error.value=true}finally{if(!controller.signal.aborted)loading.value=false} }
onMounted(()=>void load()); onBeforeUnmount(()=>controller?.abort());
</script>

<template>
  <section class="admin-revenue-page" aria-labelledby="revenue-title">
    <header><p class="admin-eyebrow">Planning</p><h1 id="revenue-title">Omzet</h1><p>Betrouwbare omzet op basis van het nieuwste opgeslagen prijssnapshot per boeking.</p></header>
    <form class="admin-revenue-filters" aria-label="Omzetperiode kiezen" @submit.prevent="load"><AdminDateField v-model="startDate" name="revenue-start" label="Begindatum" :max="endDate || undefined" :error="periodError ? 'Controleer de gekozen periode.' : null" required/><AdminDateField v-model="endDate" name="revenue-end" label="Einddatum" :min="startDate || undefined" :error="periodError || null" required/><AdminButton type="submit" :disabled="loading || Boolean(periodError)">Rapport tonen</AdminButton></form>
    <p class="admin-revenue-period"><strong>Actieve bezoekperiode:</strong> {{ periodLabel }}</p>
    <div v-if="loading" class="admin-revenue-state" role="status">Omzetrapportage laden…</div>
    <div v-else-if="error" class="admin-revenue-state" role="alert"><p>De omzetrapportage kon niet worden geladen.</p><AdminButton @click="load">Opnieuw proberen</AdminButton></div>
    <template v-else-if="report"><div class="admin-revenue-cards"><article v-for="card in cards" :key="card[0]"><span>{{ card[0] }}</span><strong>{{ card[1] }}</strong></article></div><div class="admin-revenue-breakdowns"><RevenueBreakdown title="Definitieve omzet" description="Complete snapshots van definitieve boekingen." :amounts="report.definitiveRevenue"/><RevenueBreakdown title="Potentiële omzet" description="Complete snapshots van boekingen in optie; dit is geen gerealiseerde omzet." :amounts="report.potentialRevenue"/></div><section class="admin-revenue-list" aria-labelledby="revenue-bookings-title"><h2 id="revenue-bookings-title">Onderliggende boekingen</h2><p v-if="report.bookings.length===0" class="admin-revenue-state">Geen boekingen binnen deze bezoekperiode.</p><RevenueBookingsTable v-else :bookings="report.bookings"/></section></template>
  </section>
</template>
