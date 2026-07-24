<script setup lang="ts">
import { inject,ref,watch } from "vue";
import AdminButton from "../form/AdminButton.vue";import AdminNumberField from "../form/AdminNumberField.vue";import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import { adminBootstrapKey } from "../../types/admin";import type { BookingValidationIssue } from "../../types/bookingStatus";import { BookingAttendanceApiError,updateDashboardBookingAttendance } from "../../services/dashboardBookingAttendanceApi";
import { createBookingAttendanceRequest, reconcileAttendanceOverrideState } from "../../services/bookingAttendancePresentation";
const props=defineProps<{bookingId:number;studentCount:number|null;supervisorCount:number|null}>();const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");const attendanceCsrfToken=bootstrap.bookingAttendanceCsrfToken;
const editing=ref(false),submitting=ref(false),students=ref<number|null>(props.studentCount),supervisors=ref<number|null>(props.supervisorCount),issues=ref<BookingValidationIssue[]>([]),reasons=ref<Record<string,string>>({}),dialog=ref(false),error=ref<string|null>(null);
watch(()=>[props.studentCount,props.supervisorCount] as const,([s,p])=>{if(!editing.value){students.value=s;supervisors.value=p;}});
function open(){students.value=props.studentCount;supervisors.value=props.supervisorCount;error.value=null;editing.value=true;}function cancel(){if(submitting.value)return;editing.value=false;dialog.value=false;}
async function save(withOverrides=false){if(submitting.value||students.value===null||supervisors.value===null||props.studentCount===null||props.supervisorCount===null)return;submitting.value=true;error.value=null;try{const overrides=withOverrides?issues.value.map(i=>({ruleCode:i.code,reason:(reasons.value[i.code]??"").trim()})):[];const result=await updateDashboardBookingAttendance(createBookingAttendanceRequest({bookingId:props.bookingId,expectedStudentCount:props.studentCount,expectedSupervisorCount:props.supervisorCount,studentCount:students.value,supervisorCount:supervisors.value},overrides),attendanceCsrfToken);editing.value=false;dialog.value=false;const advisory=result.validationIssues.some(issue=>issue.overridable);emit("completed",advisory?"De aantallen zijn opgeslagen. Bij definitief maken kan voor deze afwijking een reden nodig zijn. Er is geen e-mail verstuurd en de status is niet veranderd.":"De aantallen zijn gewijzigd. Er is geen e-mail verstuurd en de status is niet veranderd.",true,false);}catch(e){if(e instanceof BookingAttendanceApiError&&e.result){if(e.result.code==="OVERRIDE_REQUIRED"||e.result.code==="INVALID_OVERRIDE_REQUEST"){const current=reconcileAttendanceOverrideState(e.result.validationIssues,reasons.value);issues.value=current.issues;reasons.value=current.reasons;dialog.value=true;return;}if(e.result.code==="ATTENDANCE_CONFLICT"){editing.value=false;emit("completed","De aantallen zijn inmiddels door iemand anders gewijzigd. De actuele aanvraag is opnieuw geladen.",true,true);return;}error.value=e.result.code==="NO_CHANGES"?"Er zijn geen wijzigingen om op te slaan.":"De aantallen konden niet worden gewijzigd.";}else error.value="De aantallen konden niet worden gewijzigd.";}finally{submitting.value=false;}}
</script>
<template>
  <section class="admin-card admin-attendance-panel">
    <h2>Leerlingen en begeleiders</h2>

    <template v-if="!editing">
      <div class="admin-attendance-summary">
        <dl class="admin-details">
          <dt>Leerlingen</dt>
          <dd>{{ studentCount ?? "Niet opgegeven" }}</dd>

          <dt>Begeleiders</dt>
          <dd>{{ supervisorCount ?? "Niet opgegeven" }}</dd>
        </dl>

        <div class="admin-attendance-summary__actions">
          <AdminButton
            :disabled="studentCount === null || supervisorCount === null"
            @click="open"
          >
            Aantallen wijzigen
          </AdminButton>
        </div>
      </div>
    </template>

    <form
      v-else
      class="admin-attendance-form"
      @submit.prevent="save(false)"
    >
      <div class="admin-attendance-form__fields">
        <AdminNumberField
          v-model="students"
          label="Leerlingen"
          name="student-count"
          :min="1"
          :disabled="submitting"
        />

        <AdminNumberField
          v-model="supervisors"
          label="Begeleiders"
          name="supervisor-count"
          :min="0"
          :max="50"
          :disabled="submitting"
        />
      </div>

      <p
        v-if="error"
        class="admin-field__message admin-field__message--error"
        role="alert"
      >
        {{ error }}
      </p>

      <div class="admin-attendance-form__actions">
        <AdminButton
          variant="secondary"
          :disabled="submitting"
          @click="cancel"
        >
          Annuleren
        </AdminButton>

        <AdminButton
          type="submit"
          :loading="submitting"
          :disabled="students === null || supervisors === null"
        >
          Wijzigingen opslaan
        </AdminButton>
      </div>
    </form>

    <details class="admin-attendance-help">
      <summary>Hoe werkt het wijzigen van aantallen?</summary>

      <div
        class="admin-attendance-help__table"
        role="table"
        aria-label="Uitleg over het wijzigen van aantallen"
      >
        <div
          class="admin-attendance-help__header"
          role="row"
        >
          <span role="columnheader">Situatie</span>
          <span role="columnheader">Wat gebeurt er?</span>
        </div>

        <div
          class="admin-attendance-help__row"
          role="row"
        >
          <span
            class="admin-attendance-help__situation"
            role="cell"
          >
            In optie of afgewezen
          </span>

          <span role="cell">
            Technisch geldige aantallen kunnen direct worden aangepast.
          </span>
        </div>

        <div
          class="admin-attendance-help__row"
          role="row"
        >
          <span
            class="admin-attendance-help__situation"
            role="cell"
          >
            Definitieve aanvraag
          </span>

          <span role="cell">
            De aantallen en de dagcapaciteit worden na iedere wijziging gecontroleerd.
          </span>
        </div>

        <div
          class="admin-attendance-help__row"
          role="row"
        >
          <span
            class="admin-attendance-help__situation"
            role="cell"
          >
            Toegestane afwijking
          </span>

          <span role="cell">
            Een reden is verplicht. De wijziging en uitzondering worden
            vastgelegd.
          </span>
        </div>

        <div
          class="admin-attendance-help__row"
          role="row"
        >
          <span
            class="admin-attendance-help__situation"
            role="cell"
          >
            Status en e-mail
          </span>

          <span role="cell">
            De status verandert niet automatisch en er wordt geen e-mail
            verstuurd.
          </span>
        </div>
      </div>
    </details>

    <BookingRuleOverrideDialog
      :open="dialog"
      :issues="issues"
      :submitting="submitting"
      :reasons="reasons"
      @close="dialog = false"
      @update:reason="(code, value) => reasons[code] = value"
      @confirm="save(true)"
    />
  </section>
</template>
