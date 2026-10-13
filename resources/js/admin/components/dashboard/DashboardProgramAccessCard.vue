<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";

type Access = { fiveModuleOpen: boolean; revision: number };
const access = ref<Access | null>(null);
const busy = ref(false);
const error = ref("");
const success = ref("");
let csrfToken = "";
let flashTimer: ReturnType<typeof setTimeout> | undefined;
const controller = new AbortController();
const endpoint = "/api/admin/program-access.php";

async function load() {
  error.value = "";
  busy.value = true;
  try {
    const response = await fetch(endpoint, { signal: controller.signal, cache: "no-store" });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error("Instelling kon niet worden geladen. Vernieuw de pagina of log opnieuw in.");
    access.value = result.data as Access;
    csrfToken = result.csrfToken as string;
  } catch (caught) {
    if (!controller.signal.aborted) error.value = caught instanceof Error ? caught.message : "Laden mislukt.";
  } finally { busy.value = false; }
}

async function toggle() {
  if (!access.value || busy.value) return;
  busy.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await fetch(endpoint, {
      method: "POST", signal: controller.signal,
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify({ fiveModuleOpen: !access.value.fiveModuleOpen, revision: access.value.revision }),
    });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(response.status === 409
        ? "Een andere planner heeft deze instelling gewijzigd. Laad de actuele instelling opnieuw."
        : "Opslaan mislukt. Vernieuw de pagina of log opnieuw in.");
    }
    access.value = result.data as Access;
    success.value = "Instelling opgeslagen.";
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { success.value = ""; }, 4000);
  } catch (caught) {
    if (!controller.signal.aborted) error.value = caught instanceof Error ? caught.message : "Opslaan mislukt.";
  } finally { busy.value = false; }
}

onMounted(load);
onBeforeUnmount(() => { controller.abort(); clearTimeout(flashTimer); });
</script>

<template>
  <section class="admin-card program-access" aria-labelledby="program-access-heading">
    <div>
      <p class="admin-eyebrow">Onderwijsaanbod</p>
      <h2 id="program-access-heading">Aanvragen met vijf modules</h2>
      <p id="program-access-description">Bepaal of scholen het bestaande vijfmodulesprogramma kunnen aanvragen. Bestaande boekingen blijven beschikbaar.</p>
    </div>
    <button v-if="access" type="button" class="program-access__switch" role="switch"
      :aria-checked="access.fiveModuleOpen" aria-labelledby="program-access-heading"
      aria-describedby="program-access-description" :disabled="busy" @click="toggle">
      <span class="program-access__track" :class="{ 'is-open': access.fiveModuleOpen }" aria-hidden="true"><span /></span>
      {{ access.fiveModuleOpen ? 'Open' : 'Gesloten' }}
    </button>
    <p v-else-if="busy" role="status">Instelling laden…</p>
    <p v-if="success" role="status">{{ success }}</p>
    <div v-if="error" role="alert"><p>{{ error }}</p><AdminButton :disabled="busy" @click="load">Opnieuw laden</AdminButton></div>
  </section>
</template>

<style scoped>
.program-access { margin-block: 1.5rem; padding: 1.5rem; display: grid; gap: 1rem; }
.program-access__switch { display: flex; align-items: center; gap: .75rem; width: fit-content; color: inherit; background: transparent; border: 0; padding: .5rem; font: inherit; cursor: pointer; }
.program-access__switch:focus-visible { outline: 2px solid currentColor; outline-offset: 4px; }
.program-access__switch:disabled { opacity: .6; cursor: wait; }
.program-access__track { display: inline-flex; align-items: center; width: 3rem; height: 1.7rem; border-radius: 1rem; padding: .2rem; background: var(--color-text-muted, #64748b); }
.program-access__track span { width: 1.3rem; height: 1.3rem; border-radius: 50%; background: var(--admin-header-text); }
.program-access__track.is-open { background: var(--admin-header-bg); justify-content: flex-end; }
</style>
