<script setup lang="ts">
import { inject, ref, watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import { adminBootstrapKey } from "../../types/admin";
import type { DashboardRosterPlan, RosterBookingRefreshPreview } from "../../types/roster";
import { previewRosterBookingRefresh, applyRosterBookingRefresh } from "../../services/dashboardRosterApi";

const props = defineProps<{ plan: DashboardRosterPlan }>();
const emit = defineEmits<{ changed: [] }>();
const bootstrap = inject(adminBootstrapKey)!;
const preview = ref<RosterBookingRefreshPreview | null>(null);
const busy = ref(false);
const accepted = ref(false);
const error = ref("");
let request = 0;
watch(() => [props.plan.id, props.plan.revision], () => { request++; preview.value = null; accepted.value = false; error.value = ""; });
function date(value: string) { return value.split("-").reverse().join("-"); }
async function inspect() {
  if (busy.value) return;
  const current = ++request;
  busy.value = true; error.value = ""; accepted.value = false;
  try {
    const result = await previewRosterBookingRefresh(props.plan.id, props.plan.revision, bootstrap.rosterBookingRefreshCsrfToken);
    if (current === request) preview.value = result.preview;
  } catch (e) { error.value = e instanceof Error ? e.message : "De wijzigingen konden niet worden geladen."; }
  finally { busy.value = false; }
}
async function apply() {
  if (!preview.value || !accepted.value || !preview.value.canApply || busy.value) return;
  busy.value = true; error.value = "";
  try {
    await applyRosterBookingRefresh(preview.value, bootstrap.rosterBookingRefreshCsrfToken);
    preview.value = null; accepted.value = false; emit("changed");
  } catch (e) {
    error.value = e instanceof Error ? e.message : "De wijzigingen konden niet worden opgeslagen.";
    preview.value = null; accepted.value = false;
  } finally { busy.value = false; }
}
</script>

<template>
  <section class="admin-card admin-roster-review" :aria-labelledby="`booking-review-${plan.id}`">
    <h2 :id="`booking-review-${plan.id}`">Aanvraag en rooster controleren</h2>
    <p v-if="plan.recommendedGroupCount != null && plan.recommendedGroupCount !== plan.groups.length">
      Dit rooster heeft <strong>{{ plan.groups.length }} groepen</strong>. De huidige aanvraag met {{ plan.education.studentCount }} leerlingen gaat uit van <strong>{{ plan.recommendedGroupCount }} groepen</strong>.
    </p>
    <p v-else>De aanvraag is gewijzigd sinds dit rooster werd gemaakt.</p>
    <AdminButton v-if="!preview" variant="secondary" :loading="busy" @click="inspect">Aanvraagwijzigingen bekijken</AdminButton>
    <template v-else>
      <dl v-if="preview.changes.length" class="admin-roster-review__changes">
        <div v-for="change in preview.changes" :key="change.label"><dt><strong>{{ change.label }}</strong></dt><dd>{{ change.before }} → {{ change.after }}</dd></div>
      </dl>
      <p v-if="preview.resetLessons" class="admin-inline-notice admin-inline-notice--plain">
        <strong>Lesrooster en docentindeling opnieuw opbouwen</strong><br />
        Bij verwerken worden alle {{ preview.affectedLessons.length }} opgeslagen lessen en hun docent- en koktoewijzingen verwijderd. Daarna maak je een nieuw voorstel voor het gewijzigde programma, met de bijbehorende dagindeling.
      </p>
      <p v-else-if="preview.clearStaffing" class="admin-inline-notice admin-inline-notice--plain">
        <strong>Lessen behouden, docenten opnieuw indelen</strong><br />
        De lessen en tijden van behouden groepen blijven staan. Bij verwerken worden de docent- en koktoewijzingen van dit rooster leeggemaakt{{ preview.staffedLessonCount ? ` (${preview.staffedLessonCount} lessen met ingepland personeel)` : '' }}.
      </p>
      <p v-else-if="preview.changeType !== 'unresolved'">De groepsindeling blijft gelijk. Het lesrooster en de docentindeling blijven behouden.</p>
      <div class="admin-roster-review__changes">
        <div><strong>Groepen</strong>{{ preview.beforeGroupCount }} → {{ preview.afterGroupCount }}<br />{{ preview.studentCount }} leerlingen</div>
        <div><strong>Bezoekdatum</strong>{{ date(preview.beforeDate) }} → {{ date(preview.afterDate) }}</div>
        <div><strong>Lessen behouden</strong>{{ preview.preservedLessonCount }} ongewijzigd · {{ preview.remainingLessonCount }} lessen na verwerking</div>
      </div>
      <p><strong>Programma volgens de aanvraag:</strong> {{ preview.moduleLabels.join(' · ') }}</p>
      <p v-if="preview.removedGroups.length"><strong>Groepen die vervallen:</strong> {{ preview.removedGroups.map(g => g.label).join(', ') }}.</p>
      <p v-if="preview.addedGroupCount">Er komen {{ preview.addedGroupCount }} lege groepen bij. Daarvoor moet je nog lessen en begeleiding indelen.</p>
      <div v-if="preview.affectedLessons.length">
        <strong>Deze lessen veranderen</strong>
        <ul>
          <li v-for="lesson in preview.affectedLessons" :key="lesson.id">
            {{ lesson.startTime }}–{{ lesson.endTime }} · {{ lesson.moduleLabel }} · {{ lesson.groupLabels.join(', ') }}:
            {{ lesson.remainingGroupIds.length ? `deelname vervalt voor ${lesson.removedGroupLabels.join(', ')}; de overige groepen en lestijden blijven staan` : 'de les en bijbehorende docentindeling vervallen' }}.
          </li>
        </ul>
      </div>
      <p v-if="preview.clearStaffing">Je medewerkerselectie en voorkeuren blijven bewaard. Controleer daarna de beschikbaarheid en laat de wizard de docenten opnieuw verdelen.</p>
      <p>Er verandert pas iets nadat je hieronder bevestigt. De oude indeling wordt bewaard voor herstel. Controleer na verwerking het volledige rooster.</p>
      <p v-if="plan.jointPlanning">Het rooster van de andere school blijft staan. Controleer daarna beide scholen opnieuw samen op ruimte en personeel.</p>
      <p v-if="preview.blockedReason" class="admin-inline-error" role="alert">{{ preview.blockedReason }}</p>
      <p v-else-if="!preview.canApply">Er zijn geen aanvraagwijzigingen meer te verwerken.</p>
      <label v-else class="admin-roster-review__accept">
        <input v-model="accepted" type="checkbox" :disabled="busy" />
        <span>Ik heb de gevolgen gecontroleerd en wil deze aanvraagwijzigingen verwerken{{ preview.resetLessons ? ', inclusief het leegmaken van alle lessen en docentindeling' : preview.clearStaffing ? ', inclusief het leegmaken van de docentindeling en de genoemde leswijzigingen' : '' }}.</span>
      </label>
      <div class="admin-roster-review__actions">
        <AdminButton :disabled="!accepted || !preview.canApply" :loading="busy" @click="apply">Aanvraagwijzigingen verwerken</AdminButton>
        <AdminButton variant="secondary" :disabled="busy" @click="preview = null; accepted = false">Annuleren</AdminButton>
      </div>
    </template>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
  </section>
</template>
