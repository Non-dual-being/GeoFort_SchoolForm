<script setup lang="ts">
import { inject,ref,watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminDateField from "../form/AdminDateField.vue";
import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import { adminBootstrapKey } from "../../types/admin";
import type { BookingValidationIssue } from "../../types/bookingStatus";
import { BookingVisitDateApiError,updateDashboardBookingVisitDate } from "../../services/dashboardBookingVisitDateApi";

const props=defineProps<{bookingId:number;visitDate:string;programLabel:string;status:string;refreshing:boolean}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");
const visitDateCsrfToken=bootstrap.bookingVisitDateCsrfToken;
const editing=ref(false),submitting=ref(false),proposed=ref(props.visitDate),issues=ref<BookingValidationIssue[]>([]),reasons=ref<Record<string,string>>({}),dialog=ref(false),error=ref<string|null>(null);
watch(()=>props.visitDate,value=>{if(!editing.value)proposed.value=value;});
function formatDate(value:string):string{const [y,m,d]=value.split("-").map(Number);if(!y||!m||!d)return value;const text=new Intl.DateTimeFormat("nl-NL",{weekday:"long",day:"numeric",month:"long",year:"numeric",timeZone:"UTC"}).format(new Date(Date.UTC(y,m-1,d)));return text.charAt(0).toUpperCase()+text.slice(1);}
function open(){proposed.value=props.visitDate;error.value=null;issues.value=[];reasons.value={};editing.value=true;}
function cancel(){if(submitting.value)return;editing.value=false;dialog.value=false;}
async function save(withOverrides=false){if(submitting.value||props.refreshing||!proposed.value)return;submitting.value=true;error.value=null;try{const overrides=withOverrides?issues.value.map(i=>({ruleCode:i.code,reason:(reasons.value[i.code]??"").trim()})):[];const result=await updateDashboardBookingVisitDate({bookingId:props.bookingId,expected:{visitDate:props.visitDate},proposed:{visitDate:proposed.value},overrides},visitDateCsrfToken);editing.value=false;dialog.value=false;emit("completed",result.code==="NO_VISIT_DATE_CHANGE"?"De bezoekdatum is niet gewijzigd.":"De bezoekdatum is gewijzigd. De status is behouden en er is geen e-mail verstuurd.",result.code==="SUCCESS",false);}catch(caught){if(caught instanceof BookingVisitDateApiError&&caught.result){const result=caught.result;if(result.code==="OVERRIDE_REQUIRED"||result.code==="INVALID_OVERRIDE_REQUEST"){issues.value=result.validationIssues.filter(i=>i.overridable);const next:Record<string,string>={};for(const issue of issues.value)next[issue.code]=reasons.value[issue.code]??"";reasons.value=next;dialog.value=true;return;}if(result.code==="VISIT_DATE_CONFLICT"){emit("completed","De bezoekdatum is inmiddels door iemand anders gewijzigd. De actuele datum is geladen; controleer uw gekozen datum opnieuw.",true,true);return;}const dateIssue=result.validationIssues.find(i=>i.field==="bezoekdatum"||i.field==="programma");error.value=dateIssue?.description??(result.code==="INVALID_VISIT_DATE"?"Kies een geldige bezoekdatum.":"De bezoekdatum kon niet worden gewijzigd.");}else error.value="De bezoekdatum kon niet worden gewijzigd.";}finally{submitting.value=false;}}
</script>

<template>
  <section class="admin-card admin-visit-date-panel">
    <h2>Bezoekdatum</h2>
    <template v-if="!editing">
      <p class="admin-visit-date-panel__date">{{ formatDate(visitDate) }}</p>
      <AdminButton :disabled="refreshing" @click="open">Wijzigen</AdminButton>
    </template>
    <form v-else class="admin-visit-date-panel__form" @submit.prevent="save(false)">
      <dl class="admin-details">
        <dt>Huidige datum</dt><dd>{{ formatDate(visitDate) }}</dd>
        <dt>Status</dt><dd>{{ status }} (blijft ongewijzigd)</dd>
        <dt>Programma</dt><dd>{{ programLabel }}</dd>
      </dl>
      <AdminDateField v-model="proposed" label="Nieuwe bezoekdatum" name="visit-date" required :disabled="submitting||refreshing" :error="error" hint="De datum moet passen bij het gekozen programma." />
      <div class="admin-visit-date-panel__actions">
        <AdminButton variant="secondary" :disabled="submitting" @click="cancel">Annuleren</AdminButton>
        <AdminButton type="submit" :loading="submitting" :disabled="!proposed||refreshing">Opslaan</AdminButton>
      </div>
    </form>
    <BookingRuleOverrideDialog :open="dialog" :issues="issues" :submitting="submitting" :reasons="reasons" @close="dialog=false" @update:reason="(code,value)=>reasons[code]=value" @confirm="save(true)" />
  </section>
</template>
