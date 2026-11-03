<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import { inferPeriodSelection, months, periodBounds, periodHasWeekday, periodSummary, validDate, weekdays, type PeriodKind, type PeriodSelection, type ProgramPeriod } from "../../utils/programConfiguration";

const props = defineProps<{ period: ProgramPeriod; editing: boolean; busy: boolean }>();
const emit = defineEmits<{ save: [period: ProgramPeriod]; cancel: []; dirty: [value: boolean] }>();
const selection = reactive<PeriodSelection>(validDate(props.period.from) ? inferPeriodSelection(props.period) : {
  kind: "day", year: new Date().getFullYear(), firstMonth: 1, lastMonth: 12, from: "", until: "",
});
const open = ref(props.period.open), selectedDays = ref([...props.period.weekdays]), note = ref(props.period.note);
const error = ref(""), heading = ref<HTMLElement | null>(null), errorElement = ref<HTMLElement | null>(null);
const kinds: Array<{ key: PeriodKind; title: string; help: string }> = [
  { key: "day", title: "Eén dag", help: "Een specifieke bezoekdatum" },
  { key: "months", title: "Maanden van een jaar", help: "Vanaf een maand tot en met een maand" },
  { key: "year", title: "Heel kalenderjaar", help: "1 januari tot en met 31 december" },
  { key: "custom", title: "Vrije periode", help: "Zelf begin- en einddatum kiezen" },
];
const bounds = computed(() => periodBounds(selection));
const effectiveDays = computed(() => selection.kind === "day" && validDate(selection.from)
  ? [new Date(`${selection.from}T12:00:00`).getDay() || 7] : [...selectedDays.value].sort());
const proposed = computed<ProgramPeriod | null>(() => bounds.value ? {
  program: props.period.program, ...bounds.value, weekdays: effectiveDays.value, open: open.value, note: note.value.trim(),
} : null);
const summary = computed(() => proposed.value && effectiveDays.value.length && effectiveDays.value.every(day => day <= 5) ? periodSummary(proposed.value) : "");
const state = () => JSON.stringify([selection, open.value, selectedDays.value, note.value]);
const initial = state();
watch(state, value => { emit("dirty", value !== initial); error.value = ""; });
async function submit() {
  error.value = !proposed.value ? "Controleer de periode. De einddatum moet op of na de begindatum liggen."
    : !effectiveDays.value.length ? "Kies minimaal één weekdag."
    : effectiveDays.value.some(day => day > 5) ? "Kies een werkdag. Onderwijsprogramma’s worden niet in het weekend opengesteld."
    : !periodHasWeekday(proposed.value) ? "De gekozen weekdagen vallen niet binnen deze periode. Pas de dagen of de periode aan." : "";
  if (error.value) { await nextTick(); errorElement.value?.focus(); return; }
  if (proposed.value) emit("save", proposed.value);
}
onMounted(async () => { await nextTick(); heading.value?.focus(); });
</script>

<template>
  <form class="program-config__editor" @submit.prevent="submit">
    <h2 ref="heading" tabindex="-1">{{ editing ? 'Periode wijzigen' : 'Periode toevoegen' }}</h2>
    <p v-if="error" ref="errorElement" class="program-config__error" role="alert" tabindex="-1">{{ error }}</p>
    <fieldset :disabled="busy" class="program-config__fieldset">
      <legend>Welke periode wil je instellen?</legend>
      <div class="program-config__period-kinds">
        <label v-for="kind in kinds" :key="kind.key" :class="{ selected: selection.kind === kind.key }">
          <input v-model="selection.kind" type="radio" name="period-kind" :value="kind.key">
          <span><strong>{{ kind.title }}</strong><small>{{ kind.help }}</small></span>
        </label>
      </div>
      <div class="program-config__fields">
        <label v-if="selection.kind === 'day'">Datum<input v-model="selection.from" type="date" required></label>
        <template v-if="selection.kind === 'months' || selection.kind === 'year'">
          <label>Jaar<input v-model.number="selection.year" type="number" min="1900" max="9999" step="1" inputmode="numeric" required></label>
          <template v-if="selection.kind === 'months'">
            <label>Vanaf maand<select v-model.number="selection.firstMonth"><option v-for="(month, index) in months" :key="month" :value="index + 1">{{ month }}</option></select></label>
            <label>Tot en met maand<select v-model.number="selection.lastMonth"><option v-for="(month, index) in months" :key="month" :value="index + 1">{{ month }}</option></select></label>
          </template>
        </template>
        <template v-if="selection.kind === 'custom'">
          <label>Begindatum<input v-model="selection.from" type="date" required></label>
          <label>Einddatum<input v-model="selection.until" type="date" :min="selection.from" required></label>
        </template>
      </div>
      <fieldset v-if="selection.kind !== 'day'" class="program-config__fieldset">
        <legend>Welke weekdagen binnen deze periode?</legend>
        <div class="program-config__weekdays"><label v-for="(day,index) in weekdays" :key="day"><input v-model="selectedDays" type="checkbox" :value="index + 1">{{ day }}</label></div>
      </fieldset>
      <fieldset class="program-config__fieldset">
        <legend>Wat geldt op deze dagen?</legend>
        <div class="program-config__weekdays"><label><input v-model="open" type="radio" name="period-open" :value="true">Open voor aanvragen</label><label><input v-model="open" type="radio" name="period-open" :value="false">Gesloten voor aanvragen</label></div>
      </fieldset>
      <label class="program-config__note">Interne toelichting <span class="program-config__help">· optioneel</span><input v-model="note" maxlength="160" placeholder="Bijvoorbeeld: extra ochtenden in het voorjaar"></label>
      <div v-if="summary" class="program-config__notice" role="status"><strong>Dit ga je opslaan</strong><p>{{ summary }}</p><p class="program-config__help">De overige dagen volgen de standaardinstelling. Vakanties, dagblokkades en capaciteit blijven gelden.</p></div>
      <div class="program-config__actions"><AdminButton type="submit" :disabled="busy">{{ busy ? 'Opslaan…' : 'Periode opslaan' }}</AdminButton><AdminButton variant="secondary" :disabled="busy" @click="emit('cancel')">Annuleren</AdminButton></div>
    </fieldset>
  </form>
</template>
