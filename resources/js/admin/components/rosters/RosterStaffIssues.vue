<script setup lang="ts">
import { computed, inject, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";
import { adminBootstrapKey } from "../../types/admin";
import type { DashboardRosterPlan } from "../../types/roster";
import { rosterStaffIssues } from "../../services/rosterStaffIssues";
import { saveRosterStaffSelection } from "../../services/dashboardRosterApi";

const props = defineProps<{ plan: DashboardRosterPlan }>();
const emit = defineEmits<{ changed: [] }>();
const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const token = bootstrap.rosterStaffSelectionCsrfToken;
const issues = computed(() => rosterStaffIssues(props.plan));
const selected = computed(() => props.plan.planning.selectedStaffIds.map(id => ({
  id, member: props.plan.planning.staffCatalog.find(m => m.id === id),
})));
const busy = ref(false); const error = ref<string | null>(null);
function assigned(id: number): boolean {
  return props.plan.planning.staffingSettings.cookStaffId === id
    || props.plan.planning.sessions.some(session => session.staffAssignments.some(s => s.id === id)
      || session.groupStaffAssignments?.some(group => group.staffAssignments.some(s => s.id === id)));
}
async function remove(id: number): Promise<void> {
  if (busy.value) return;
  busy.value = true; error.value = null;
  try {
    await saveRosterStaffSelection(props.plan.id, props.plan.revision, props.plan.planning.catalogRevision ?? 0,
      props.plan.planning.selectedStaffIds.filter(value => value !== id), token);
    emit("changed");
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Dagselectie kon niet worden opgeslagen."; }
  finally { busy.value = false; }
}
</script>
<template>
  <section v-if="plan.status === 'concept'" class="admin-roster-staff-issues">
    <div v-if="issues.length" class="admin-inline-error" role="alert">
      <strong>Personeelswijzigingen blokkeren finalisatie</strong>
      <ul><li v-for="issue in issues" :key="issue.staffId">{{ issue.name }}: {{ issue.reasons.join(" ") }}</li></ul>
      <p>Vervang ongeldige toewijzingen via handmatige correcties. Verwijder daarna overgebleven medewerkers expliciet uit de dagselectie.</p>
    </div>
    <details v-if="selected.length">
      <summary>Dagselectie beheren ({{ selected.length }})</summary>
      <p>Een medewerker met een les- of koktoewijzing kan pas worden verwijderd nadat die toewijzing is vervangen.</p>
      <ul>
        <li v-for="item in selected" :key="item.id">
          <span>{{ item.member?.name ?? 'Medewerker #' + item.id }}{{ item.member?.isActive ? "" : " · inactief" }}</span>
          <AdminButton variant="secondary" :disabled="busy || assigned(item.id)" :aria-label="'Verwijder ' + (item.member?.name ?? item.id) + ' uit dagselectie'" @click="remove(item.id)">Uit dagselectie verwijderen</AdminButton>
        </li>
      </ul>
    </details>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
  </section>
</template>
