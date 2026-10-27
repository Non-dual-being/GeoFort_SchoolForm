<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import AdminButton from "./form/AdminButton.vue";
import AdminSelect from "./form/AdminSelect.vue";
import { downloadDashboardRosterWeekXlsx } from "../services/dashboardRosterExportApi";

const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const weeks = ref<{ key: string; label: string; rosterCount: number }[]>([]);
const selectedWeek = ref("");
const loading = ref(true);
const downloading = ref(false);
const error = ref("");
const message = ref("");
const options = computed(() => weeks.value.map(week => ({ value: week.key, label: `${week.label} · ${week.rosterCount} roosters` })));

onMounted(async () => {
  dialog.value?.showModal();
  try {
    const response = await fetch("/api/admin/rosters/week-export.php", { credentials: "same-origin", headers: { Accept: "application/json" } });
    if (response.redirected) throw new Error("Je sessie is verlopen. Log opnieuw in.");
    if (!response.ok) throw new Error(response.status === 401 ? "Je sessie is verlopen. Log opnieuw in." : "De roosterweken konden niet worden geladen.");
    const body = await response.json();
    weeks.value = body.data.weeks;
    selectedWeek.value = weeks.value[0]?.key ?? "";
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : "De roosterweken konden niet worden geladen.";
  } finally { loading.value = false; }
});

async function download() {
  if (!selectedWeek.value || downloading.value) return;
  downloading.value = true; error.value = ""; message.value = "";
  try {
    const filename = await downloadDashboardRosterWeekXlsx(selectedWeek.value);
    message.value = `${filename} is gedownload.`;
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Downloaden is niet gelukt."; }
  finally { downloading.value = false; }
}
</script>

<template>
  <dialog ref="dialog" class="admin-week-export admin-card" aria-labelledby="week-export-title" @close="emit('close')" @cancel="emit('close')">
    <header class="admin-week-export__header">
      <div><p class="admin-eyebrow">Excel-export</p><h2 id="week-export-title">Weekoverzicht</h2></div>
      <AdminButton variant="secondary" aria-label="Weekoverzicht sluiten" @click="emit('close')">Sluiten</AdminButton>
    </header>
    <p>Alle roosters van aanvragen in optie of definitief. Het eerste tabblad bevat de totalen en links naar de roosters; elke weekdag heeft een eigen tabblad.</p>
    <p v-if="loading" role="status">Beschikbare weken laden…</p>
    <p v-else-if="!weeks.length && !error">Er zijn nog geen weken met roosters voor aanvragen in optie of definitief.</p>
    <AdminSelect v-else-if="weeks.length" v-model="selectedWeek" name="roster-export-week" label="Kies een week" :options="options" />
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
    <p v-if="message" class="admin-inline-notice" role="status">{{ message }}</p>
    <footer class="admin-week-export__footer">
      <AdminButton :disabled="loading || !selectedWeek" :loading="downloading" @click="download">Weekoverzicht downloaden</AdminButton>
    </footer>
  </dialog>
</template>
