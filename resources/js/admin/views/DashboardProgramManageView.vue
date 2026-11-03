<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute } from "vue-router";
import { ArrowLeft, Plus, Sunrise, Layers3 } from "lucide-vue-next";
import AdminButton from "../components/form/AdminButton.vue";
import AdminDialog from "../components/feedback/AdminDialog.vue";
import ProgramPeriodEditor from "../components/configuration/ProgramPeriodEditor.vue";
import { useProgramConfiguration } from "../composables/useProgramConfiguration";
import { formatDate, periodSummary, programDateExplanation, programLabels, validDate, type ProgramKind, type ProgramPeriod } from "../utils/programConfiguration";

const props = defineProps<{ program: ProgramKind }>();
const route = useRoute();
const { config, busy, error, message, load, save } = useProgramConfiguration();
const fiveOpen = ref(true), editorDirty = ref(false);
const editor = ref<{ index: number | null; period: ProgramPeriod; key: number } | null>(null);
const heading = ref<HTMLElement | null>(null), errorBox = ref<HTMLElement | null>(null), addButtonArea = ref<HTMLElement | null>(null);
let editorKey = 0;
const confirmation = ref<{ title: string; description: string; action: string } | null>(null);
let resolveConfirmation: ((confirmed: boolean) => void) | undefined;
function confirmAction(title: string, description: string, action: string): Promise<boolean> {
  if (confirmation.value) return Promise.resolve(false);
  confirmation.value = { title, description, action };
  return new Promise(resolve => { resolveConfirmation = resolve; });
}
function finishConfirmation(confirmed: boolean) {
  confirmation.value = null;
  resolveConfirmation?.(confirmed); resolveConfirmation = undefined;
}
const periods = computed(() => config.value?.periods.map((period, index) => ({ period, index })).filter(item => item.period.program === props.program) ?? []);
const standardDirty = computed(() => props.program === "five" && !!config.value && fiveOpen.value !== config.value.fiveModuleOpen);
const dirty = computed(() => standardDirty.value || editorDirty.value);
const selectedDate = computed(() => typeof route.query.date === "string" && validDate(route.query.date) ? route.query.date : "");
const dateExplanation = computed(() => config.value && selectedDate.value ? programDateExplanation(config.value, props.program, selectedDate.value) : null);
watch(config, value => { if (value) fiveOpen.value = value.fiveModuleOpen; });
async function allowLeave() { return !busy.value && (!dirty.value || await confirmAction("Wijzigingen niet opslaan?", "Je hebt niet-opgeslagen wijzigingen. De opgeslagen instellingen blijven behouden.", "Verder zonder opslaan")); }
function beforeUnload(event: BeforeUnloadEvent) { if (dirty.value || busy.value) { event.preventDefault(); event.returnValue = ""; } }
onBeforeRouteLeave(allowLeave); onBeforeRouteUpdate(allowLeave);
async function reload() {
  if (!await allowLeave()) return;
  if (await load()) { editor.value = null; editorDirty.value = false; }
}
function editPeriod(index: number | null) {
  if (!config.value || standardDirty.value || busy.value) return;
  const period = index === null ? { program: props.program, from: selectedDate.value, until: selectedDate.value, weekdays: [], open: true, note: "" } : config.value.periods[index];
  if (!period) return;
  editorDirty.value = false;
  editor.value = { index, period: { ...period, weekdays: [...period.weekdays] }, key: ++editorKey };
}
async function cancel() {
  if (editorDirty.value && !await confirmAction("Periodewijziging annuleren?", "De niet-opgeslagen wijziging vervalt. De opgeslagen instellingen blijven behouden.", "Wijziging laten vervallen")) return;
  editor.value = null; editorDirty.value = false; await nextTick(); addButtonArea.value?.querySelector('button')?.focus();
}
async function savePeriod(period: ProgramPeriod) {
  if (!config.value || !editor.value) return;
  const next = config.value.periods.map(item => ({ ...item, weekdays: [...item.weekdays] }));
  if (editor.value.index === null) next.push(period); else next[editor.value.index] = period;
  if (await save({ ...config.value, periods: next })) { editor.value = null; editorDirty.value = false; }
  await nextTick(); if (error.value) errorBox.value?.focus(); else heading.value?.focus();
}
async function saveStandard() {
  if (config.value) await save({ ...config.value, fiveModuleOpen: fiveOpen.value });
  await nextTick(); if (error.value) errorBox.value?.focus();
}
async function remove(index: number) {
  if (!config.value || !await confirmAction("Periode verwijderen?", "Op deze dagen geldt daarna weer de standaardinstelling. Bestaande aanvragen blijven behouden.", "Periode verwijderen")) return;
  await save({ ...config.value, periods: config.value.periods.filter((_, i) => i !== index) });
  await nextTick(); if (error.value) errorBox.value?.focus(); else heading.value?.focus();
}
async function enter() { await load(); await nextTick(); heading.value?.focus(); }
watch(() => props.program, () => { editor.value = null; editorDirty.value = false; void enter(); });
onMounted(() => { window.addEventListener("beforeunload", beforeUnload); void enter(); });
onBeforeUnmount(() => { window.removeEventListener("beforeunload", beforeUnload); resolveConfirmation?.(false); });
</script>

<template>
  <div class="program-config">
    <AdminDialog :open="!!confirmation" :title="confirmation?.title ?? ''" :description="confirmation?.description" @close="finishConfirmation(false)">
      <template #footer><AdminButton variant="secondary" @click="finishConfirmation(false)">Terug</AdminButton><AdminButton @click="finishConfirmation(true)">{{ confirmation?.action }}</AdminButton></template>
    </AdminDialog>
    <RouterLink class="program-config__back" to="/configuratie"><ArrowLeft :size="18" aria-hidden="true" /> Terug naar configuratie</RouterLink>
    <header><p class="admin-eyebrow">Bekijken en beheren</p><h1 ref="heading" tabindex="-1"><component :is="program === 'morning' ? Sunrise : Layers3" :size="28" aria-hidden="true" />{{ programLabels[program] }}</h1><p>Stel in wanneer dit programma kan worden aangevraagd.</p></header>
    <p v-if="message" class="program-config__notice" role="status">{{ message }}</p>
    <div v-if="error" ref="errorBox" class="program-config__error" role="alert" tabindex="-1"><p>{{ error }}</p><AdminButton variant="secondary" :disabled="busy" @click="reload">Actuele instellingen laden</AdminButton></div>
    <p v-if="busy && !config" role="status">Instellingen laden…</p>
    <template v-if="config">
      <p v-if="dateExplanation" class="program-config__notice">{{ formatDate(selectedDate) }}: <strong>{{ dateExplanation.open ? 'opengesteld' : 'gesloten' }}</strong>. {{ dateExplanation.explanation }}</p>
      <section class="program-config__card" aria-labelledby="standard-heading"><h2 id="standard-heading">Standaardinstelling</h2>
        <template v-if="program === 'morning'"><p><strong>Woensdag · 10:00–12:15</strong></p><p>Voor primair en speciaal basisonderwijs. Met een periode hieronder kun je extra werkdagen openen of ochtenden sluiten.</p></template>
        <form v-else @submit.prevent="saveStandard">
          <button class="program-config__switch" type="button" role="switch" aria-label="Vijfmoduleprogramma standaard open" :aria-checked="fiveOpen" :disabled="busy || !!editor" @click="fiveOpen = !fiveOpen"><span :class="{'is-open':fiveOpen}" aria-hidden="true"><i /></span> {{ fiveOpen ? 'Open op werkdagen' : 'Gesloten' }}</button>
          <p>Periodes hieronder kunnen hiervan afwijken. Het gewone formulier biedt vanaf 4 januari 2027 standaard vier modules aan.</p>
          <a href="/?variant=five" target="_blank" rel="noopener noreferrer">Vijfmoduleformulier bekijken ↗</a>
          <div v-if="standardDirty" class="program-config__actions"><AdminButton type="submit" :disabled="busy">Standaardinstelling opslaan</AdminButton><AdminButton variant="secondary" :disabled="busy" @click="fiveOpen = config.fiveModuleOpen">Annuleren</AdminButton></div>
        </form>
      </section>
      <section class="program-config__card" aria-labelledby="periods-heading"><h2 id="periods-heading">Ingestelde periodes</h2><p>Alleen de gekozen weekdagen veranderen, inclusief begin- en einddatum.</p>
        <p v-if="!periods.length" class="program-config__empty">Er zijn geen uitzonderingen. De standaardinstelling geldt voor alle datums.</p>
        <ul v-else class="program-config__period-list"><li v-for="item in periods" :key="item.index">
          <div><strong>{{ periodSummary(item.period) }}</strong><p v-if="item.period.note" class="program-config__help">{{ item.period.note }}</p></div>
          <div class="program-config__actions"><AdminButton variant="secondary" :disabled="busy || !!editor || standardDirty" :aria-label="`Wijzigen: ${periodSummary(item.period)}`" @click="editPeriod(item.index)">Wijzigen</AdminButton><button class="program-config__text-button" :disabled="busy || !!editor || standardDirty" :aria-label="`Verwijderen: ${periodSummary(item.period)}`" @click="remove(item.index)">Verwijderen</button></div>
        </li></ul>
        <div v-if="!editor && config.periods.length < 50" ref="addButtonArea" class="program-config__actions"><AdminButton :disabled="busy || standardDirty" @click="editPeriod(null)"><Plus :size="18" aria-hidden="true" /> Periode toevoegen</AdminButton></div>
        <p v-if="config.periods.length >= 50" class="program-config__help">Er zijn maximaal 50 periodes beschikbaar voor beide programma’s samen.</p>
      </section>
      <ProgramPeriodEditor v-if="editor" :key="editor.key" :period="editor.period" :editing="editor.index !== null" :busy="busy" @dirty="editorDirty = $event" @save="savePeriod" @cancel="cancel" />
      <p class="program-config__help">Een wijziging werkt door in het publieke formulier en nieuwe datum- of programmakeuzes in het dashboard. Bestaande aanvragen, prijzen en roosters blijven behouden. Vakanties, dagblokkades en capaciteit blijven gelden.</p>
    </template>
  </div>
</template>
