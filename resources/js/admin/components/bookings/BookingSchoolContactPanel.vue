<script setup lang="ts">
import {computed,inject,nextTick,reactive,ref,watch} from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminConfirmationControl from "../form/AdminConfirmationControl.vue";
import AdminContextCard from "../form/AdminContextCard.vue";
import AdminInlineNotice from "../form/AdminInlineNotice.vue";
import AdminInput from "../form/AdminInput.vue";
import AdminSelect from "../form/AdminSelect.vue";
import AdminWizardActionBar from "../wizard/AdminWizardActionBar.vue";
import AdminWizardStepper from "../wizard/AdminWizardStepper.vue";
import {adminBootstrapKey} from "../../types/admin";
import type {DashboardBookingDetail} from "../../types/bookingDetail";
import type {BookingSchoolContactDetails} from "../../types/bookingSchoolContact";
import {BookingSchoolContactApiError,updateDashboardBookingSchoolContact} from "../../services/dashboardBookingSchoolContactApi";
import {createInitialBookingForm} from "../../../config/booking/BookingFields";
import {normalizeEmail,normalizePhoneNumber,normalizePostcode,validateField} from "../../../config/validation/booking";
import type {BookingField,CountryCode} from "../../../types/booking/BookingFieldTypes";

const props=defineProps<{bookingId:number;school:DashboardBookingDetail["school"];contact:DashboardBookingDetail["contact"]}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken=bootstrap.bookingSchoolContactCsrfToken;
const STEP_IDS=["school-details","contact-review"] as const;
const fieldMap={schoolName:"schoolnaam",country:"land",address:"adres",postalCode:"postcode",city:"plaats",schoolPhone:"schoolTelefoonnummer",contactFirstName:"contactpersoonVoornaam",contactLastName:"contactpersoonAchternaam",contactEmail:"email",contactPhone:"contactpersoonTelefoonnummer"} as const satisfies Record<keyof BookingSchoolContactDetails,BookingField>;
const editing=ref(false),step=ref(1),submitting=ref(false),confirmed=ref(false),attempted=ref(false),conflict=ref(false);
const errors=reactive<Partial<Record<keyof BookingSchoolContactDetails,string>>>({});
const touched=reactive<Partial<Record<keyof BookingSchoolContactDetails,boolean>>>({});
let expected:BookingSchoolContactDetails;
const values=reactive<BookingSchoolContactDetails>({schoolName:"",country:"Nederland",address:"",postalCode:"",city:"",schoolPhone:"",contactFirstName:"",contactLastName:"",contactEmail:"",contactPhone:""});
const schoolFields=(["schoolName","country","address","postalCode","city","schoolPhone"] as const);
const contactFields=(["contactFirstName","contactLastName","contactEmail","contactPhone"] as const);
const countryOptions=[{value:"Nederland",label:"Nederland"},{value:"België",label:"België"}];
const wizardSteps=computed(()=>STEP_IDS.map((id,index)=>({label:index===0?"Schoolgegevens":"Contactpersoon en controle",state:(index+1===step.value?"current":index+1<step.value?"completed":"upcoming") as "current"|"completed"|"upcoming",error:attempted.value&&index===step.value-1&&Object.keys(errors).length>0})));
const errorSummary=computed(()=>Object.values(errors).filter((message):message is string=>Boolean(message)));

function snapshot():BookingSchoolContactDetails{return {schoolName:props.school.name,country:props.school.country,address:props.school.address,postalCode:props.school.postalCode,city:props.school.city,schoolPhone:props.school.phone,contactFirstName:props.contact.firstName,contactLastName:props.contact.lastName,contactEmail:props.contact.email,contactPhone:props.contact.phone};}
function reset():void{expected=snapshot();Object.assign(values,expected);Object.keys(errors).forEach(key=>delete errors[key as keyof BookingSchoolContactDetails]);Object.keys(touched).forEach(key=>delete touched[key as keyof BookingSchoolContactDetails]);step.value=1;confirmed.value=false;attempted.value=false;conflict.value=false;}
function open():void{reset();editing.value=true;}
function cancel():void{editing.value=false;reset();}
function publicValues(){const form=createInitialBookingForm();form.schoolnaam=values.schoolName;form.land=values.country;form.adres=values.address;form.postcode=values.postalCode;form.plaats=values.city;form.schoolTelefoonnummer=values.schoolPhone;form.contactpersoonVoornaam=values.contactFirstName;form.contactpersoonAchternaam=values.contactLastName;form.email=values.contactEmail;form.contactpersoonTelefoonnummer=values.contactPhone;return form;}
function validate(keys:readonly (keyof BookingSchoolContactDetails)[]):boolean{const form=publicValues();for(const key of keys){const issue=validateField(fieldMap[key],values[key],form);const message=issue.error??issue.warning;if(message)errors[key]=message;else delete errors[key];}return keys.every(key=>!errors[key]);}
function blur(key:keyof BookingSchoolContactDetails):void{touched[key]=true;validate([key]);}
function changed(key:keyof BookingSchoolContactDetails):void{confirmed.value=false;conflict.value=false;if(touched[key]||attempted.value)validate([key]);}
const inputNames:Record<keyof BookingSchoolContactDetails,string>={schoolName:"school-name",country:"school-country",address:"school-address",postalCode:"school-postal-code",city:"school-city",schoolPhone:"school-phone",contactFirstName:"contact-first-name",contactLastName:"contact-last-name",contactEmail:"contact-email",contactPhone:"contact-phone"};
async function focusFirst(keys:readonly (keyof BookingSchoolContactDetails)[]):Promise<void>{const key=keys.find(item=>errors[item]);if(!key)return;await nextTick();document.getElementById(`${key==="country"?"admin-select":"admin-input"}-${inputNames[key]}`)?.focus();}
async function next():Promise<void>{attempted.value=true;if(!validate(schoolFields)){await focusFirst(schoolFields);return;}values.postalCode=normalizePostcode(values.postalCode,values.country as CountryCode);values.schoolPhone=normalizePhoneNumber(values.schoolPhone);step.value=2;attempted.value=false;}
function back():void{step.value=1;attempted.value=false;}
function normalize():void{values.postalCode=normalizePostcode(values.postalCode,values.country as CountryCode);values.schoolPhone=normalizePhoneNumber(values.schoolPhone);values.contactPhone=normalizePhoneNumber(values.contactPhone);values.contactEmail=normalizeEmail(values.contactEmail);}
async function save():Promise<void>{if(submitting.value)return;attempted.value=true;if(!validate([...schoolFields,...contactFields])){const schoolInvalid=schoolFields.some(key=>errors[key]);if(schoolInvalid)step.value=1;await focusFirst(schoolInvalid?schoolFields:contactFields);return;}if(!confirmed.value)return;normalize();submitting.value=true;
 try{const result=await updateDashboardBookingSchoolContact({bookingId:props.bookingId,expected,proposed:{...values}},csrfToken);editing.value=false;emit("completed",result.code==="NO_SCHOOL_CONTACT_CHANGE"?"De school- en contactgegevens zijn niet gewijzigd.":"De school- en contactgegevens zijn bijgewerkt.",result.code==="SUCCESS",false);}
 catch(exception){if(exception instanceof BookingSchoolContactApiError){if(exception.result?.code==="SCHOOL_CONTACT_CONFLICT"){conflict.value=true;confirmed.value=false;emit("completed","De aanvraag is inmiddels gewijzigd. Controleer de actuele gegevens opnieuw; uw invoer is behouden.",true,true);return;}for(const issue of exception.result?.validationIssues??[])errors[issue.field]=issue.description;}const schoolInvalid=schoolFields.some(key=>errors[key]);if(schoolInvalid)step.value=1;await focusFirst(schoolInvalid?schoolFields:contactFields);}
 finally{submitting.value=false;}}
watch(()=>[props.school,props.contact],()=>{if(editing.value&&conflict.value)expected=snapshot();},{deep:true});
function display(value:string):string{return value.trim()||"Niet opgegeven";}
</script>
<template>
  <section class="admin-card admin-booking-detail__wide">
    <h2>School- en contactgegevens</h2>
    <template v-if="!editing">
      <div class="admin-school-contact-overview">
        <dl class="admin-details"><dt>School</dt><dd>{{ display(school.name) }}</dd><dt>Adres</dt><dd>{{ display(school.address) }}</dd><dt>Postcode en plaats</dt><dd>{{ `${school.postalCode} ${school.city}`.trim()||"Niet opgegeven" }}</dd><dt>Land</dt><dd>{{ display(school.country) }}</dd><dt>Schooltelefoon</dt><dd>{{ display(school.phone) }}</dd><dt>Contactpersoon</dt><dd>{{ `${contact.firstName} ${contact.lastName}`.trim()||"Niet opgegeven" }}</dd><dt>E-mailadres</dt><dd>{{ display(contact.email) }}</dd><dt>Telefoonnummer</dt><dd>{{ display(contact.phone) }}</dd></dl>
        <AdminButton @click="open">Gegevens wijzigen</AdminButton>
      </div>
    </template>
    <form v-else class="admin-school-contact-form" @submit.prevent>
      <AdminWizardStepper :steps="wizardSteps" />
      <div v-if="errorSummary.length" class="admin-inline-notice admin-inline-notice--error" role="alert" aria-live="assertive"><strong>Controleer de volgende velden:</strong><ul><li v-for="message in errorSummary" :key="message">{{ message }}</li></ul></div>
      <AdminInlineNotice v-if="conflict" variant="error">De actuele gegevens zijn opnieuw geladen. Uw invoer is behouden; controleer deze opnieuw.</AdminInlineNotice>
      <fieldset :disabled="submitting">
        <template v-if="step===1">
          <legend>Schoolgegevens</legend>
          <AdminInput v-model="values.schoolName" name="school-name" label="Naam van de school" required :error="errors.schoolName" @blur="blur('schoolName')" @update:model-value="changed('schoolName')" />
          <AdminSelect v-model="values.country" name="school-country" label="Land van de school" :options="countryOptions" required :error="errors.country" @update:model-value="changed('country')" />
          <AdminInput v-model="values.address" name="school-address" label="Adres van de school" required autocomplete="street-address" :error="errors.address" @blur="blur('address')" @update:model-value="changed('address')" />
          <AdminInput v-model="values.postalCode" name="school-postal-code" label="Postcode" required autocomplete="postal-code" :error="errors.postalCode" @blur="blur('postalCode')" @update:model-value="changed('postalCode')" />
          <AdminInput v-model="values.city" name="school-city" label="Plaats" required autocomplete="address-level2" :error="errors.city" @blur="blur('city')" @update:model-value="changed('city')" />
          <AdminInput v-model="values.schoolPhone" name="school-phone" label="Telefoonnummer van de school" type="tel" inputmode="tel" required autocomplete="tel" :error="errors.schoolPhone" @blur="blur('schoolPhone')" @update:model-value="changed('schoolPhone')" />
        </template>
        <template v-else>
          <legend>Contactpersoon en controle</legend>
          <AdminInput v-model="values.contactFirstName" name="contact-first-name" label="Voornaam" required autocomplete="given-name" :error="errors.contactFirstName" @blur="blur('contactFirstName')" @update:model-value="changed('contactFirstName')" />
          <AdminInput v-model="values.contactLastName" name="contact-last-name" label="Achternaam" required autocomplete="family-name" :error="errors.contactLastName" @blur="blur('contactLastName')" @update:model-value="changed('contactLastName')" />
          <AdminInput v-model="values.contactEmail" name="contact-email" label="E-mailadres" type="email" inputmode="email" required autocomplete="email" :error="errors.contactEmail" @blur="blur('contactEmail')" @update:model-value="changed('contactEmail')" />
          <AdminInput v-model="values.contactPhone" name="contact-phone" label="Telefoonnummer contactpersoon" type="tel" inputmode="tel" required autocomplete="tel" :error="errors.contactPhone" @blur="blur('contactPhone')" @update:model-value="changed('contactPhone')" />
          <AdminContextCard label="Gewijzigde schoolgegevens" :value="`${values.schoolName} · ${values.address}, ${values.postalCode} ${values.city} · ${values.country} · ${values.schoolPhone}`" />
          <AdminConfirmationControl v-model="confirmed" label="Ik heb de school- en contactgegevens gecontroleerd." :error="attempted&&!confirmed?'Bevestig dat u de gegevens hebt gecontroleerd.':null" />
        </template>
      </fieldset>
      <AdminWizardActionBar :step="step" :total-steps="2" :submitting="submitting" :next-disabled="step===2&&!confirmed" submit-label="Gegevens opslaan" @back="back" @cancel="cancel" @next="next" @submit="save" />
    </form>
  </section>
</template>
