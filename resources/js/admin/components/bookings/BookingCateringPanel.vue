<script setup lang="ts">
import {computed,inject,reactive,ref,watch} from "vue";
import AdminButton from "../form/AdminButton.vue";
import {adminBootstrapKey} from "../../types/admin";
import type {DashboardBookingDetail} from "../../types/bookingDetail";
import type {BookingCateringOption,BookingLunchChoice} from "../../types/bookingCatering";
import {BookingCateringApiError,updateDashboardBookingCatering} from "../../services/dashboardBookingCateringApi";

const props=defineProps<{bookingId:number;foodAndDrink:DashboardBookingDetail["foodAndDrink"]}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");
const cateringCsrfToken=bootstrap.bookingCateringCsrfToken;
const remiseLunchOption=computed<BookingCateringOption>(()=>{const option=props.foodAndDrink.options.lunch.remise_lunch;if(!option)throw new Error("Remiselunchconfiguratie ontbreekt.");return option;});
const ownPicnicOption=computed<BookingCateringOption>(()=>{const option=props.foodAndDrink.options.lunch.eigen_picknick;if(!option)throw new Error("Picknickconfiguratie ontbreekt.");return option;});
const editing=ref(false),submitting=ref(false),conflictDetected=ref(false),conflictRefreshReady=ref(false),error=ref<string|null>(null),issues=ref<Record<string,string>>({});
const values=reactive({remiseBreak:0,kazerneBreak:0,fortgrachtBreak:0,waterIce:0,lemonade:0,remiseLunch:0});
const lunchChoice=ref<BookingLunchChoice|null>(null);
const snackDefinitions=computed(()=>[
  ["remiseBreak",props.foodAndDrink.options.snacks.remise_break],
  ["kazerneBreak",props.foodAndDrink.options.snacks.kazerne_break],
  ["fortgrachtBreak",props.foodAndDrink.options.snacks.fortgracht_break],
  ["waterIce",props.foodAndDrink.options.snacks.waterijsje],
  ["lemonade",props.foodAndDrink.options.snacks.glas_limonade],
] as Array<[keyof typeof values,BookingCateringOption]>);
const orderedSnacks=computed(()=>snackDefinitions.value.filter(([key])=>props.foodAndDrink[key]>0));
const preview=computed(()=>snackDefinitions.value.reduce((sum,[key,option])=>sum+values[key]*option.price,0)+(lunchChoice.value==="remise_lunch"?values.remiseLunch*remiseLunchOption.value.price:0));
watch(()=>props.foodAndDrink,()=>{if(!editing.value)reset();else if(conflictDetected.value)conflictRefreshReady.value=true;},{deep:true});
function reset(){for(const key of ["remiseBreak","kazerneBreak","fortgrachtBreak","waterIce","lemonade","remiseLunch"] as const)values[key]=props.foodAndDrink[key];lunchChoice.value=["remise_lunch","eigen_picknick"].includes(props.foodAndDrink.lunchChoice)?props.foodAndDrink.lunchChoice as BookingLunchChoice:null;conflictDetected.value=false;conflictRefreshReady.value=false;issues.value={};error.value=null;}
function open(){reset();editing.value=true;}function cancel(){if(!submitting.value)editing.value=false;}
function toggle(key:keyof typeof values,option:BookingCateringOption,event:Event){values[key]=(event.target as HTMLInputElement).checked?(values[key]||option.min):0;}
function choose(choice:BookingLunchChoice){lunchChoice.value=choice;if(choice==="eigen_picknick")values.remiseLunch=0;else if(values.remiseLunch===0)values.remiseLunch=remiseLunchOption.value.min;}
function numberInput(key:keyof typeof values,event:Event){const raw=(event.target as HTMLInputElement).value;values[key]=/^\d+$/.test(raw)?Number(raw):0;}
function formatCurrency(value:number){return new Intl.NumberFormat("nl-NL",{style:"currency",currency:"EUR"}).format(value);}
async function save(){
  if(submitting.value||conflictDetected.value||!lunchChoice.value)return;submitting.value=true;error.value=null;issues.value={};
  try{
    const result=await updateDashboardBookingCatering({bookingId:props.bookingId,expected:{remiseBreak:props.foodAndDrink.remiseBreak,kazerneBreak:props.foodAndDrink.kazerneBreak,fortgrachtBreak:props.foodAndDrink.fortgrachtBreak,waterIce:props.foodAndDrink.waterIce,lemonade:props.foodAndDrink.lemonade,remiseLunch:props.foodAndDrink.remiseLunch,ownPicnic:props.foodAndDrink.ownPicnic},proposed:{...values,lunchChoice:lunchChoice.value}},cateringCsrfToken);
    editing.value=false;emit("completed",result.code==="NO_CATERING_CHANGE"?"Er zijn geen wijzigingen om op te slaan.":"Eten en drinken zijn bijgewerkt.",result.code==="SUCCESS",false);
  }catch(exception){
    if(exception instanceof BookingCateringApiError&&exception.result){
      if(exception.result.code==="CATERING_CONFLICT"){conflictDetected.value=true;conflictRefreshReady.value=false;error.value="Een andere planner heeft de cateringgegevens gewijzigd. Neem de actuele gegevens over en voer uw wijziging daarna opnieuw in.";emit("completed","Een andere planner heeft de cateringgegevens gewijzigd. De actuele gegevens worden opnieuw geladen.",true,true);return;}
      if(["INVALID_CATERING_SELECTION","INVALID_STORED_BOOKING"].includes(exception.result.code)){for(const issue of exception.result.validationIssues)issues.value[issue.field]=issue.description||issue.title||message(issue.code);error.value="Controleer de gemarkeerde cateringkeuzes.";return;}
    }error.value="Eten en drinken konden niet worden gewijzigd.";
  }finally{submitting.value=false;}
}
function message(code:string){return ({MISSING_LUNCH_SELECTION:"Kies Remiselunch of Eigen picknick.",INVALID_REMISE_LUNCH_QUANTITY:"Het aantal remiselunches valt buiten de toegestane grenzen.",CATERING_OPTION_LIMIT_EXCEEDED:"Dit aantal is hoger dan toegestaan.",CONFLICTING_LUNCH_SELECTION:"De opgeslagen lunchgegevens spreken elkaar tegen."} as Record<string,string>)[code]??"Deze cateringkeuze is ongeldig.";}
</script>
<template>
  <section class="admin-card admin-catering-panel">
    <h2>Eten en drinken</h2>
    <template v-if="!editing">
      <dl v-if="orderedSnacks.length" class="admin-details">
        <template v-for="[key,option] in orderedSnacks" :key="key"><dt>{{ option.label }}</dt><dd>{{ foodAndDrink[key] }}</dd></template>
      </dl>
      <p v-else class="admin-booking-detail__muted">Geen extra eten of drinken besteld.</p>
      <p class="admin-catering-lunch"><strong>Lunch</strong>
        <span v-if="foodAndDrink.lunchChoice==='conflict'" class="admin-field__message admin-field__message--error">Foutieve opgeslagen lunchgegevens: Remiselunch en eigen picknick zijn beide vastgelegd.</span>
        <span v-else-if="foodAndDrink.lunchChoice==='eigen_picknick'">Eigen picknick</span>
        <span v-else-if="foodAndDrink.lunchChoice==='remise_lunch'">Remiselunch — {{ foodAndDrink.remiseLunch }} stuks</span>
        <span v-else>Geen lunchkeuze vastgelegd.</span>
      </p>
      <AdminButton @click="open">Wijzigen</AdminButton>
    </template>
    <form v-else class="admin-catering-form" @submit.prevent="save">
      <fieldset :disabled="submitting"><legend>Snacks en drinken</legend>
        <div v-for="[key,option] in snackDefinitions" :key="key" class="admin-catering-option">
          <label><input type="checkbox" :checked="values[key]>0" @change="toggle(key,option,$event)"> <strong>{{ option.label }}</strong></label>
          <span>{{ option.description }} · {{ option.min }}–{{ option.max }} · {{ formatCurrency(option.price) }}</span>
          <input v-if="values[key]>0" type="number" inputmode="numeric" step="1" :min="option.min" :max="option.max" :aria-label="`Aantal ${option.label}`" :value="values[key]" @input="numberInput(key,$event)">
          <p v-if="issues[key]" class="admin-field__message admin-field__message--error">{{ issues[key] }}</p>
        </div>
      </fieldset>
      <fieldset :disabled="submitting"><legend>Lunch</legend>
        <label><input type="radio" name="catering-lunch" :checked="lunchChoice==='remise_lunch'" @change="choose('remise_lunch')"> {{ remiseLunchOption.label }} · {{ formatCurrency(remiseLunchOption.price) }}</label>
        <input v-if="lunchChoice==='remise_lunch'" type="number" inputmode="numeric" step="1" :min="remiseLunchOption.min" :max="remiseLunchOption.max" aria-label="Aantal remiselunches" :value="values.remiseLunch" @input="numberInput('remiseLunch',$event)">
        <label><input type="radio" name="catering-lunch" :checked="lunchChoice==='eigen_picknick'" @change="choose('eigen_picknick')"> {{ ownPicnicOption.label }} · {{ formatCurrency(ownPicnicOption.price) }}</label>
        <p v-if="issues.lunchChoice||issues.remiseLunch" class="admin-field__message admin-field__message--error">{{ issues.lunchChoice||issues.remiseLunch }}</p>
      </fieldset>
      <p class="admin-catering-preview">Voorlopige cateringextra’s: <strong>{{ formatCurrency(preview) }}</strong></p>
      <p v-if="error" class="admin-field__message admin-field__message--error" role="alert">{{ error }}</p>
      <div v-if="conflictDetected" class="admin-catering-conflict">
        <p>Actuele serverwaarden:</p>
        <dl class="admin-details">
          <template v-for="[key,option] in snackDefinitions" :key="key"><dt>{{ option.label }}</dt><dd>{{ foodAndDrink[key] }}</dd></template>
          <dt>Lunch</dt>
          <dd v-if="foodAndDrink.lunchChoice==='remise_lunch'">{{ remiseLunchOption.label }} — {{ foodAndDrink.remiseLunch }} stuks</dd>
          <dd v-else-if="foodAndDrink.lunchChoice==='eigen_picknick'">{{ ownPicnicOption.label }}</dd>
          <dd v-else-if="foodAndDrink.lunchChoice==='conflict'">Conflict: Remiselunch en eigen picknick zijn beide vastgelegd.</dd>
          <dd v-else>Geen lunchkeuze vastgelegd.</dd>
        </dl>
        <p>Neem deze gegevens over voordat u opnieuw opslaat.</p>
        <AdminButton variant="secondary" :disabled="submitting||!conflictRefreshReady" @click="reset">Actuele gegevens overnemen</AdminButton>
      </div>
      <div class="admin-catering-actions"><AdminButton variant="secondary" :disabled="submitting" @click="cancel">Annuleren</AdminButton><AdminButton type="submit" :loading="submitting" :disabled="conflictDetected||!lunchChoice">Opslaan</AdminButton></div>
    </form>
  </section>
</template>
