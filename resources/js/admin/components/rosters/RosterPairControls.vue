<script setup lang="ts">
import { computed, inject, ref, watch } from "vue";
import { useRouter } from "vue-router";
import AdminButton from "../form/AdminButton.vue";
import { linkRosterPair, archiveRosterPair } from "../../services/dashboardRosterApi";
import { adminBootstrapKey } from "../../types/admin";
import type { DashboardRosterPlan } from "../../types/roster";

const props = defineProps<{ plans: DashboardRosterPlan[] }>();
const emit = defineEmits<{ changed: [] }>();
const bootstrap = inject(adminBootstrapKey)!;
const router = useRouter();
const pair = computed(() => props.plans[0]?.jointPlanning);
const totalGroups = computed(() => props.plans.reduce((sum, plan) => sum + plan.groups.length, 0));
const tooManyGroups = computed(() => totalGroups.value > 10);
const workflow = ref<"lessons_first" | "school_first">("lessons_first");
const restartDialog = ref(false);
const restartAccepted = ref(false);
watch(() => props.plans.map(p => `${p.id}:${p.revision}`).join(","), () => { restartDialog.value = false; restartAccepted.value = false; });
const confirming = ref(false);
const acknowledged = ref(false);
const busy = ref(false);
const error = ref("");
async function link() {
  if (!restartAccepted.value || busy.value || tooManyGroups.value) return;
  busy.value = true; error.value = "";
  try {
    await linkRosterPair(props.plans, workflow.value, bootstrap.rosterPairLinkCsrfToken!);
    emit("changed");
  } catch (e) { error.value = e instanceof Error ? e.message : "Koppelen is niet gelukt."; }
  finally { busy.value = false; }
}
async function archive() {
  if (!pair.value || !acknowledged.value) return;
  busy.value = true; error.value = "";
  try {
    await archiveRosterPair(props.plans, pair.value, bootstrap.rosterPairArchiveCsrfToken!);
    await router.replace({ name: "rosters" });
  } catch (e) { error.value = e instanceof Error ? e.message : "Opheffen is niet gelukt. Laad beide roosters opnieuw."; }
  finally { busy.value = false; }
}
</script>

<template>
  <section class="admin-card pair-controls" aria-labelledby="pair-controls-title">
    <h2 id="pair-controls-title">{{ pair ? "Gekoppelde schoolroosters" : "Samen plannen" }}</h2>
    <p v-if="pair">Deze scholen zijn samen opgeslagen. Ook bij het afzonderlijk openen gelden de gezamenlijke controles.</p>
    <template v-else>
      <p :class="tooManyGroups ? 'admin-inline-error' : 'admin-inline-notice'">Samen {{ totalGroups }} groepen · maximaal 10 groepen per koppeling.{{ tooManyGroups ? " Deze scholen kunnen niet worden gekoppeld." : "" }}</p>
      <fieldset :disabled="busy">
        <legend>Welke volgorde wil je gebruiken?</legend>
        <label><input v-model="workflow" type="radio" value="lessons_first" /> Eerst beide lesindelingen, daarna personeel</label>
        <label><input v-model="workflow" type="radio" value="school_first" /> Beginschool inclusief personeel, daarna de andere school</label>
      </fieldset>
      <AdminButton v-if="!restartDialog" :disabled="busy || plans.length !== 2 || tooManyGroups" @click="restartDialog = true">Scholen koppelen</AdminButton>
      <div v-else class="pair-controls__confirmation" role="group" aria-label="Opnieuw beginnen bevestigen">
        <p><strong>Opnieuw beginnen met beide scholen</strong></p>
        <ul><li v-for="plan in plans" :key="plan.id">{{ plan.school.name }} · {{ plan.planning.sessions.length }} lessen · {{ plan.status === 'published' ? 'definitief' : 'concept' }}</li></ul>
        <p>De bestaande lessen, docentindelingen en maatwerkinstellingen worden leeggemaakt. We starten beide roosters opnieuw vanuit het aangevraagde programma en leerlingaantal. Een definitief rooster wordt weer concept; eerdere definitieve versies en documenten blijven in de historie bewaard.</p>
        <label><input v-model="restartAccepted" type="checkbox" :disabled="busy" /> Ik geef toestemming om beide roosters leeg te maken en samen opnieuw te beginnen.</label>
        <div class="pair-controls__actions">
          <AdminButton :disabled="busy || !restartAccepted || tooManyGroups" @click="link">Leegmaken en scholen koppelen</AdminButton>
          <AdminButton variant="secondary" :disabled="busy" @click="restartDialog = false; restartAccepted = false">Annuleren</AdminButton>
        </div>
      </div>
    </template>
    <template v-if="pair">
      <p>Gekozen volgorde: {{ pair.workflow === "lessons_first" ? "eerst beide lesindelingen, daarna personeel" : "beginschool inclusief personeel, daarna de andere school" }}.</p>
      <AdminButton v-if="!confirming" variant="secondary" @click="confirming = true">Gezamenlijke planning opheffen</AdminButton>
      <div v-else class="pair-controls__confirmation" role="group" aria-label="Gezamenlijke planning opheffen bevestigen">
        <p>De roosters van <strong>{{ plans.map(p => p.school.name).join(" en ") }}</strong> worden allebei gearchiveerd, ook als ze definitief zijn. De aanvragen komen vrij voor nieuwe individuele roosters. De oude indelingen, definitieve versies en opgeslagen documenten blijven bewaard.</p>
        <label><input v-model="acknowledged" type="checkbox" :disabled="busy" /> Ik wil beide schoolroosters archiveren.</label>
        <div class="pair-controls__actions">
          <AdminButton :disabled="busy || !acknowledged" @click="archive">Beide archiveren en koppeling opheffen</AdminButton>
          <AdminButton variant="secondary" :disabled="busy" @click="confirming = false; acknowledged = false">Annuleren</AdminButton>
        </div>
      </div>
    </template>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.pair-controls, .pair-controls fieldset, .pair-controls__confirmation { display: grid; gap: .75rem; }
.pair-controls fieldset { border: 0; padding: 0; }
.pair-controls legend { margin-bottom: .5rem; font-weight: 600; }
.pair-controls label { display: flex; align-items: baseline; gap: .5rem; }
.pair-controls__actions { display: flex; flex-wrap: wrap; gap: .75rem; }
.pair-controls p { margin: 0; }
</style>
