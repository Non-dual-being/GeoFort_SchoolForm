<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import { CalendarSearch } from "lucide-vue-next";
import AdminButton from "../form/AdminButton.vue";
import { requestProgramConfiguration } from "../../services/programConfigurationApi";
import { getApiData } from "../../../services/http/apiClient";
import { formatDate, programDateExplanation, programRoutes, validDate, type ProgramConfiguration } from "../../utils/programConfiguration";
import type { DashboardCalendar, DashboardCalendarDay } from "../../types/dashboardCalendar";

const emit = defineEmits<{ refreshed: [config: ProgramConfiguration] }>();
const date = ref(""), busy = ref(false), error = ref("");
const result = ref<{ date: string; config: ProgramConfiguration; day: DashboardCalendarDay } | null>(null);
let controller: AbortController | undefined;
const rows = computed(() => result.value ? [
  { key: "morning", label: "Ochtendprogramma", route: programRoutes.morning },
  { key: "five", label: "Dagprogramma · vijf modules", route: programRoutes.five },
  { key: "four", label: "Dagprogramma · vier modules", route: null },
].map(item => ({ ...item, ...programDateExplanation(result.value!.config, item.key as "morning" | "five" | "four", result.value!.date) })) : []);
const calendarSummary = computed(() => {
  const day = result.value?.day;
  if (!day) return "";
  if (day.isPast) return "Deze datum ligt in het verleden.";
  if (day.weekday > 5) return "Deze datum valt in het weekend.";
  if (day.disabledType) return day.disabledReason || "Deze datum is geblokkeerd in de agenda.";
  if (!rows.value.some(row => row.open)) return "Geen programma opengesteld op deze datum.";
  if (day.state === "full") return "Volgeboekt: de leerling- of schoolcapaciteit is bereikt.";
  return "Geen vakantie of dagblokkade. Er is nog dagcapaciteit beschikbaar.";
});
watch(date, () => { controller?.abort(); result.value = null; error.value = ""; busy.value = false; });
async function check() {
  if (!validDate(date.value)) { error.value = "Kies een geldige bezoekdatum."; return; }
  controller?.abort(); const current = new AbortController(); controller = current;
  const selected = date.value;
  busy.value = true; error.value = ""; result.value = null;
  try {
    const query = new URLSearchParams({ startDate: selected, endDate: selected });
    const [configResponse, calendarResponse] = await Promise.all([
      requestProgramConfiguration(current.signal),
      getApiData<{ calendar: DashboardCalendar }>(`/api/admin/calendar/month.php?${query}`, { signal: current.signal, cache: "no-store", credentials: "same-origin" }),
    ]);
    if (current.signal.aborted) return;
    const day = calendarResponse.calendar.days.find(item => item.date === selected);
    if (!day) throw new Error("De kalendercontrole is niet beschikbaar. Probeer opnieuw.");
    result.value = { date: selected, config: configResponse.data, day }; emit("refreshed", configResponse.data);
  } catch (caught) { if (!current.signal.aborted) error.value = caught instanceof Error ? caught.message : "Controle mislukt."; }
  finally { if (controller === current) busy.value = false; }
}
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section id="datumcontrole" class="program-config__card" aria-labelledby="date-check-heading">
    <h2 id="date-check-heading"><CalendarSearch :size="23" aria-hidden="true" /> Bezoekdatum controleren</h2>
    <p>Bekijk het opgeslagen aanbod en de actuele dagcapaciteit voor één datum.</p>
    <form class="program-config__date-form" @submit.prevent="check"><label>Bezoekdatum<input v-model="date" type="date" required></label><AdminButton type="submit" :disabled="busy">{{ busy ? 'Controleren…' : 'Datum controleren' }}</AdminButton></form>
    <p v-if="error" class="program-config__error" role="alert">{{ error }}</p>
    <div v-if="result" class="program-config__check-result" aria-live="polite">
      <h3>{{ formatDate(result.date) }}</h3>
      <ul class="program-config__check-list"><li v-for="row in rows" :key="row.key">
        <div><strong>{{ row.label }}</strong><p class="program-config__help">{{ row.explanation }}</p><RouterLink v-if="row.route" :to="{path:row.route, query:{date: result.date}}">Instellingen bekijken →</RouterLink></div>
        <span class="program-config__tag" :class="{closed: !row.open}">{{ row.open ? 'Opengesteld' : 'Gesloten' }}</span>
      </li></ul>
      <div class="program-config__notice"><strong>Agenda en capaciteit</strong><p>{{ calendarSummary }}</p>
        <p>Definitief geboekt: {{ result.day.confirmedBookingCount }} scholen · {{ result.day.confirmedStudentCount }} leerlingen.</p>
        <p v-if="!result.day.isPast && !result.day.disabledType && result.day.weekday <= 5 && rows.some(row => row.open)">Resterende leerlingcapaciteit: {{ result.day.remainingCapacity ?? 'onbekend' }}. Dit is de gezamenlijke dagruimte; programmagrens en schoolcapaciteit worden bij de aanvraag apart getoetst.</p>
        <p v-if="result.day.optionBookingCount">Daarnaast {{ result.day.optionBookingCount }} aanvragen in optie; die reserveren nog geen capaciteit.</p>
      </div>
    </div>
  </section>
</template>
