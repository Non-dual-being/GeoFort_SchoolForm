<script setup lang="ts">
import {computed,inject,nextTick,reactive,ref,watch} from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminConfirmationControl from "../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../form/AdminInlineNotice.vue";
import AdminInput from "../form/AdminInput.vue";
import AdminSelect from "../form/AdminSelect.vue";
import {adminBootstrapKey} from "../../types/admin";
import type {DashboardBookingDetail} from "../../types/bookingDetail";
import type {BookingCjpDetails} from "../../types/bookingCjp";
import {BookingCjpApiError,updateDashboardBookingCjp} from "../../services/dashboardBookingCjpApi";
import {createInitialBookingForm} from "../../../config/booking/BookingFields";
import {normalizeCjpPasnumber,normalizeCjpPersonName,validateField} from "../../../config/validation/booking";

const props=defineProps<{bookingId:number;cjp:DashboardBookingDetail["additional"]}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrapData=inject(adminBootstrapKey);if(!bootstrapData)throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken=bootstrapData.bookingCjpCsrfToken;
const editing=ref(false),submitting=ref(false),confirmed=ref(false),attempted=ref(false),conflict=ref(false);
const errors=reactive<Partial<Record<keyof BookingCjpDetails,string>>>({});
const touched=reactive<Partial<Record<keyof BookingCjpDetails,boolean>>>({});
type BookingCjpEditorValues={useCjp:string;contactName:string;cardNumber:string};
const values=reactive<BookingCjpEditorValues>({useCjp:"nee",contactName:"",cardNumber:""});
let expected:BookingCjpDetails;
const options=[{value:"nee",label:"Nee"},{value:"ja",label:"Ja"}];
const usesCjp=computed(()=>values.useCjp==="ja");
const errorSummary=computed(()=>Object.values(errors).filter((message):message is string=>Boolean(message)));

function snapshot():BookingCjpDetails{return {useCjp:props.cjp.cjpUse,contactName:props.cjp.cjpContactName,cardNumber:props.cjp.cjpCardNumber};}
function reset():void{expected=snapshot();Object.assign(values,{useCjp:expected.useCjp,contactName:expected.contactName??"",cardNumber:expected.cardNumber??""});Object.keys(errors).forEach(key=>delete errors[key as keyof BookingCjpDetails]);Object.keys(touched).forEach(key=>delete touched[key as keyof BookingCjpDetails]);confirmed.value=false;attempted.value=false;conflict.value=false;}
function open():void{reset();editing.value=true;}
function cancel():void{editing.value=false;reset();}
function publicValues(){const form=createInitialBookingForm();form.cjpPasGebruik=values.useCjp;form.cjpContactpersoonNaam=values.contactName;form.cjpPasnummer=values.cardNumber;return form;}
const fieldMap={useCjp:"cjpPasGebruik",contactName:"cjpContactpersoonNaam",cardNumber:"cjpPasnummer"} as const;
function validate(keys:readonly (keyof BookingCjpDetails)[]):boolean{const form=publicValues();for(const key of keys){const issue=validateField(fieldMap[key],values[key],form);const message=issue.error??issue.warning;if(message)errors[key]=message;else delete errors[key];}return keys.every(key=>!errors[key]);}
function blur(key:keyof BookingCjpDetails):void{touched[key]=true;validate([key]);}
function changed(key:keyof BookingCjpDetails):void{confirmed.value=false;conflict.value=false;if(key==="useCjp"&&values.useCjp==="nee"){values.contactName="";values.cardNumber="";delete errors.contactName;delete errors.cardNumber;delete touched.contactName;delete touched.cardNumber;}if(touched[key]||attempted.value)validate(["useCjp","contactName","cardNumber"]);} 
async function focusFirst():Promise<void>{const key=(["useCjp","contactName","cardNumber"] as const).find(item=>errors[item]);if(!key)return;await nextTick();document.getElementById(key==="useCjp"?"admin-select-cjp-use":`admin-input-cjp-${key==="contactName"?"contact-name":"card-number"}`)?.focus();}
function normalize():void{values.useCjp=values.useCjp==="ja"?"ja":"nee";values.contactName=normalizeCjpPersonName(values.contactName);values.cardNumber=normalizeCjpPasnumber(values.cardNumber);}
async function save():Promise<void>{if(submitting.value)return;attempted.value=true;if(!validate(["useCjp","contactName","cardNumber"])){await focusFirst();return;}if(!confirmed.value)return;normalize();submitting.value=true;
 try{const proposed:BookingCjpDetails={useCjp:values.useCjp,contactName:values.contactName||null,cardNumber:values.cardNumber||null};const result=await updateDashboardBookingCjp({bookingId:props.bookingId,expected,proposed},csrfToken);editing.value=false;emit("completed",result.code==="NO_CJP_CHANGE"?"De CJP-gegevens zijn niet gewijzigd.":"De CJP-gegevens zijn bijgewerkt.",result.code==="SUCCESS",false);}
 catch(exception){if(exception instanceof BookingCjpApiError){if(exception.result?.code==="CJP_CONFLICT"){conflict.value=true;confirmed.value=false;emit("completed","De aanvraag is inmiddels gewijzigd. Controleer de actuele CJP-gegevens opnieuw; uw invoer is behouden.",true,true);return;}for(const issue of exception.result?.validationIssues??[])errors[issue.field]=issue.description;}await focusFirst();}
 finally{submitting.value=false;}}
watch(()=>props.cjp,()=>{if(editing.value&&conflict.value)expected=snapshot();},{deep:true});
function display(value:string|null):string{return value?.trim()||"Niet opgegeven";}
function displayUse(value:string):string{return value==="ja"?"Ja":value==="nee"?"Nee":display(value);}
</script>

<template>
  <section class="admin-card admin-booking-detail__wide admin-cjp-panel">
    <h2>CJP-gegevens</h2>
    <template v-if="!editing">
      <div class="admin-cjp-panel__summary">
        <dl class="admin-details">
          <dt>Gebruik CJP-korting</dt><dd>{{ displayUse(cjp.cjpUse) }}</dd>
          <template v-if="cjp.cjpUse==='ja'">
            <dt>CJP-contactpersoon</dt><dd>{{ display(cjp.cjpContactName) }}</dd>
            <dt>CJP-pasnummer</dt><dd>{{ display(cjp.cjpCardNumber) }}</dd>
          </template>
        </dl>
        <div class="admin-cjp-panel__edit-action">
          <AdminButton @click="open">CJP-gegevens wijzigen</AdminButton>
        </div>
      </div>
    </template>
    <form v-else class="admin-cjp-form" @submit.prevent="save">
      <div v-if="errorSummary.length" class="admin-inline-notice admin-inline-notice--error" role="alert" aria-live="assertive"><strong>Controleer de volgende velden:</strong><ul><li v-for="message in errorSummary" :key="message">{{ message }}</li></ul></div>
      <AdminInlineNotice v-if="conflict" variant="error">De actuele CJP-gegevens zijn opnieuw geladen. Uw invoer is behouden; controleer deze opnieuw.</AdminInlineNotice>
      <fieldset :disabled="submitting">
        <legend>CJP-gegevens wijzigen</legend>
        <div class="admin-cjp-form__fields">
          <AdminSelect v-model="values.useCjp" name="cjp-use" label="Maakt de school gebruik van CJP-korting?" :options="options" required :error="errors.useCjp" @blur="blur('useCjp')" @update:model-value="changed('useCjp')" />
          <AdminInlineNotice>Bij ‘Nee’ worden de ingevulde CJP-contactgegevens en het pasnummer verwijderd.</AdminInlineNotice>
          <div v-if="usesCjp" class="admin-cjp-form__conditional-fields">
            <AdminInput v-model="values.contactName" name="cjp-contact-name" label="Naam contactpersoon CJP-pas" required autocomplete="name" :error="errors.contactName" @blur="blur('contactName')" @update:model-value="changed('contactName')" />
            <AdminInput v-model="values.cardNumber" name="cjp-card-number" label="CJP-pasnummer" required inputmode="numeric" :error="errors.cardNumber" @blur="blur('cardNumber')" @update:model-value="changed('cardNumber')" />
          </div>
          <div class="admin-cjp-form__confirmation">
            <AdminConfirmationControl v-model="confirmed" label="Ik heb de CJP-gegevens gecontroleerd." :error="attempted&&!confirmed?'Bevestig dat u de CJP-gegevens hebt gecontroleerd.':null" />
          </div>
        </div>
      </fieldset>
      <div class="admin-wizard-actions admin-cjp-form__actions"><AdminButton variant="secondary" type="button" :disabled="submitting" @click="cancel">Annuleren</AdminButton><AdminButton type="submit" :disabled="submitting||!confirmed">{{ submitting?"Opslaan…":"CJP-gegevens opslaan" }}</AdminButton></div>
    </form>
  </section>
</template>
