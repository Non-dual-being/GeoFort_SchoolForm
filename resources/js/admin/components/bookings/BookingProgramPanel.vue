<script setup lang="ts">
import {computed,inject,ref} from "vue";
import AdminButton from "../form/AdminButton.vue";
import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import {adminBootstrapKey} from "../../types/admin";
import type {BookingValidationIssue} from "../../types/bookingStatus";
import {BookingProgramApiError,updateDashboardBookingProgram} from "../../services/dashboardBookingProgramApi";

type Option={key:string;label:string;description:string[];allowedSchoolTypes:string[];allowedWeekdays:number[]};
const props=defineProps<{bookingId:number;program:string;programLabel:string;schoolSector:string;schoolSectorLabel:string;visitDate:string;status:string;options:Option[];refreshing:boolean}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");
const programCsrfToken=bootstrap.bookingProgramCsrfToken;
const editing=ref(false),submitting=ref(false),proposed=ref(props.program),expected=ref(props.program),issues=ref<BookingValidationIssue[]>([]),reasons=ref<Record<string,string>>({}),dialog=ref(false),error=ref<string|null>(null),conflicted=ref(false);
const weekday=computed(()=>{const [y,m,d]=props.visitDate.split("-").map(Number);return new Date(Date.UTC(y??1970,(m??1)-1,d??1)).getUTCDay()||7;});
function formatDate(value:string):string{const [y,m,d]=value.split("-").map(Number);const text=new Intl.DateTimeFormat("nl-NL",{weekday:"long",day:"numeric",month:"long",year:"numeric",timeZone:"UTC"}).format(new Date(Date.UTC(y??1970,(m??1)-1,d??1)));return text.charAt(0).toUpperCase()+text.slice(1);}
function reason(option:Option):string|null{if(!option.allowedSchoolTypes.includes(props.schoolSector))return "Niet beschikbaar voor deze schoolsector.";if(!option.allowedWeekdays.includes(weekday.value))return "Niet beschikbaar op deze weekdag.";return null;}
function open(){proposed.value=props.program;expected.value=props.program;issues.value=[];reasons.value={};error.value=null;conflicted.value=false;editing.value=true;}
function cancel(){if(!submitting.value){editing.value=false;dialog.value=false;}}
function reconsider(){expected.value=props.program;conflicted.value=false;error.value=null;}
async function save(withOverrides=false){if(submitting.value||props.refreshing||conflicted.value)return;submitting.value=true;error.value=null;try{const overrides=withOverrides?issues.value.map(issue=>({ruleCode:issue.code,reason:(reasons.value[issue.code]??"").trim()})):[];const result=await updateDashboardBookingProgram({bookingId:props.bookingId,expected:{program:expected.value},proposed:{program:proposed.value},overrides},programCsrfToken);editing.value=false;dialog.value=false;emit("completed",result.code==="NO_PROGRAM_CHANGE"?"Het programma is niet gewijzigd.":"Het programma is gewijzigd. De status en overige aanvraaggegevens zijn behouden.",result.code==="SUCCESS",false);}catch(caught){if(caught instanceof BookingProgramApiError&&caught.result){const result=caught.result;if(result.code==="OVERRIDE_REQUIRED"||result.code==="INVALID_OVERRIDE_REQUEST"){issues.value=result.validationIssues.filter(issue=>issue.overridable);const next:Record<string,string>={};for(const issue of issues.value)next[issue.code]=reasons.value[issue.code]??"";reasons.value=next;dialog.value=true;return;}if(result.code==="PROGRAM_CONFLICT"){conflicted.value=true;error.value="Het programma is inmiddels gewijzigd. Controleer de actuele serverwaarde en beoordeel uw keuze opnieuw.";emit("completed",error.value,true,true);return;}issues.value=result.validationIssues;const fieldIssue=issues.value.find(issue=>["programma","educationSelection","keuzemodule","aantalLeerlingen","bezoekdatum"].includes(issue.field));error.value=fieldIssue?.description??"Het programma kon niet worden gewijzigd.";}else error.value="Het programma kon niet worden gewijzigd.";}finally{submitting.value=false;}}
</script>
<template>
  <section class="admin-card admin-program-panel">
    <h2>Programma</h2>
    <template v-if="!editing"><p class="admin-program-panel__value">{{ programLabel }}</p><AdminButton :disabled="refreshing" @click="open">Wijzigen</AdminButton></template>
    <form v-else class="admin-program-panel__form" @submit.prevent="save(false)">
      <dl class="admin-details"><dt>Huidig programma</dt><dd>{{ programLabel }}</dd><dt>Schoolsector</dt><dd>{{ schoolSectorLabel }}</dd><dt>Bezoekdatum</dt><dd>{{ formatDate(visitDate) }}</dd><dt>Status</dt><dd>{{ status }} (blijft ongewijzigd)</dd></dl>
      <fieldset><legend>Kies een programma</legend>
        <label v-for="option in options" :key="option.key" class="admin-program-panel__option" :class="{'admin-program-panel__option--disabled':reason(option)}">
          <input v-model="proposed" type="radio" name="program" :value="option.key" :disabled="Boolean(reason(option))"/>
          <span><strong>{{ option.label }}</strong><small v-for="line in option.description" :key="line">{{ line }}</small><small v-if="reason(option)" class="admin-program-panel__reason">{{ reason(option) }}</small></span>
        </label>
      </fieldset>
      <p v-if="error" class="admin-form-error" role="alert">{{ error }}</p>
      <AdminButton v-if="conflicted" type="button" variant="secondary" @click="reconsider">Actuele waarde herbeoordelen</AdminButton>
      <div class="admin-program-panel__actions"><AdminButton type="button" variant="secondary" :disabled="submitting" @click="cancel">Annuleren</AdminButton><AdminButton type="submit" :loading="submitting" :disabled="submitting||refreshing||conflicted">Opslaan</AdminButton></div>
    </form>
    <BookingRuleOverrideDialog :open="dialog" :issues="issues" :submitting="submitting" :reasons="reasons" @close="dialog=false" @confirm="save(true)" @update:reason="(code,value)=>reasons[code]=value"/>
  </section>
</template>
