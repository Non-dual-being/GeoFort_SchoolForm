<script setup lang="ts">
import { computed,inject,nextTick,ref,watch } from "vue";
import { ChevronLeft,ChevronRight,TriangleAlert } from "lucide-vue-next";
import AdminButton from "../form/AdminButton.vue";
import AdminDateField from "../form/AdminDateField.vue";
import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import { adminBootstrapKey } from "../../types/admin";
import type { BookingValidationIssue } from "../../types/bookingStatus";
import type { BookingVisitDateCalendarDay } from "../../types/bookingVisitDateCalendar";
import { BookingVisitDateApiError,updateDashboardBookingVisitDate } from "../../services/dashboardBookingVisitDateApi";
import { fetchDashboardBookingVisitDateCalendar } from "../../services/dashboardBookingVisitDateCalendarApi";

const props=defineProps<{bookingId:number;visitDate:string;programLabel:string;status:string;refreshing:boolean}>();
const emit=defineEmits<{completed:[message:string,refresh:boolean,conflict:boolean]}>();
const bootstrap=inject(adminBootstrapKey);if(!bootstrap)throw new Error("Admin bootstrapdata ontbreekt.");
const visitDateCsrfToken=bootstrap.bookingVisitDateCsrfToken;
const editing=ref(false),submitting=ref(false),proposed=ref(props.visitDate),issues=ref<BookingValidationIssue[]>([]),reasons=ref<Record<string,string>>({}),dialog=ref(false),error=ref<string|null>(null);
const visibleMonth=ref(monthStart(props.visitDate)),days=ref<BookingVisitDateCalendarDay[]>([]),calendarLoading=ref(false),calendarError=ref(false),focusedDate=ref(props.visitDate);
const cache=new Map<string,BookingVisitDateCalendarDay[]>(),pending=new Map<string,Promise<BookingVisitDateCalendarDay[]>>();
let controller:AbortController|undefined;
watch(()=>props.visitDate,value=>{if(!editing.value)proposed.value=value;});
const gridRange=computed(()=>{const first=parseDate(visibleMonth.value);const offset=(first.getUTCDay()+6)%7;const start=addDays(first,-offset);return {start:ymd(start),end:ymd(addDays(start,41))};});
const monthLabel=computed(()=>new Intl.DateTimeFormat("nl-NL",{month:"long",year:"numeric",timeZone:"UTC"}).format(parseDate(visibleMonth.value)));
const focusedDay=computed(()=>days.value.find(day=>day.date===focusedDate.value)??days.value.find(day=>day.date===proposed.value)??null);
const today=ymd(new Date());
function parseDate(value:string):Date{const parts=value.split("-").map(Number);return new Date(Date.UTC(parts[0]??1970,(parts[1]??1)-1,parts[2]??1));}
function ymd(date:Date):string{return `${date.getUTCFullYear()}-${String(date.getUTCMonth()+1).padStart(2,"0")}-${String(date.getUTCDate()).padStart(2,"0")}`;}
function addDays(date:Date,count:number):Date{const next=new Date(date);next.setUTCDate(next.getUTCDate()+count);return next;}
function monthStart(value:string):string{const date=parseDate(value);date.setUTCDate(1);return ymd(date);}
function formatDate(value:string):string{const text=new Intl.DateTimeFormat("nl-NL",{weekday:"long",day:"numeric",month:"long",year:"numeric",timeZone:"UTC"}).format(parseDate(value));return text.charAt(0).toUpperCase()+text.slice(1);}
function dayStateLabel(day:BookingVisitDateCalendarDay):string{return {available:"Beschikbaar",warning:"Waarschuwing",override_required:"Override nodig",blocked:"Niet beschikbaar",past:"Datum in het verleden"}[day.state];}
function ariaLabel(day:BookingVisitDateCalendarDay):string{return [formatDate(day.date),dayStateLabel(day),day.reasons[0]?.title,`${day.capacity.confirmedSchools} van ${day.capacity.schoolLimit} scholen definitief`,`${day.capacity.confirmedStudents} van ${day.capacity.studentLimit} leerlingen`].filter(Boolean).join(". ");}
async function loadCalendar(force=false):Promise<void>{const {start,end}=gridRange.value,key=`${start}/${end}`;if(!force&&cache.has(key)){days.value=cache.get(key)!;calendarError.value=false;return;}calendarLoading.value=true;calendarError.value=false;try{let request=pending.get(key);if(!request){controller?.abort();controller=new AbortController();request=fetchDashboardBookingVisitDateCalendar(props.bookingId,start,end,controller.signal).then(result=>result.calendar.days);pending.set(key,request);}const result=await request;cache.set(key,result);days.value=result;}catch(caught){if(caught instanceof DOMException&&caught.name==="AbortError")return;calendarError.value=true;}finally{pending.delete(key);calendarLoading.value=false;}}
function open(){proposed.value=props.visitDate;focusedDate.value=props.visitDate;visibleMonth.value=monthStart(props.visitDate);error.value=null;issues.value=[];reasons.value={};editing.value=true;void loadCalendar();}
function cancel(){if(submitting.value)return;editing.value=false;dialog.value=false;controller?.abort();}
function changeMonth(delta:number){const date=parseDate(visibleMonth.value);date.setUTCMonth(date.getUTCMonth()+delta);visibleMonth.value=ymd(date);focusedDate.value=visibleMonth.value;void loadCalendar();}
function selectDay(day:BookingVisitDateCalendarDay){focusedDate.value=day.date;if(day.selectable)proposed.value=day.date;}
async function focusDate(date:string){focusedDate.value=date;const element=document.querySelector<HTMLButtonElement>(`[data-calendar-date="${date}"]`);await nextTick();element?.focus();}
function keydown(event:KeyboardEvent,day:BookingVisitDateCalendarDay){const moves:Record<string,number>={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7};if(event.key==="Enter"||event.key===" "){event.preventDefault();selectDay(day);return;}const move=moves[event.key];if(!move)return;event.preventDefault();const target=ymd(addDays(parseDate(day.date),move));if(target<gridRange.value.start||target>gridRange.value.end){visibleMonth.value=monthStart(target);void loadCalendar().then(()=>focusDate(target));}else void focusDate(target);}
async function save(withOverrides=false){if(submitting.value||props.refreshing||!proposed.value)return;submitting.value=true;error.value=null;try{const overrides=withOverrides?issues.value.map(i=>({ruleCode:i.code,reason:(reasons.value[i.code]??"").trim()})):[];const result=await updateDashboardBookingVisitDate({bookingId:props.bookingId,expected:{visitDate:props.visitDate},proposed:{visitDate:proposed.value},overrides},visitDateCsrfToken);cache.clear();editing.value=false;dialog.value=false;emit("completed",result.code==="NO_VISIT_DATE_CHANGE"?"De bezoekdatum is niet gewijzigd.":"De bezoekdatum is gewijzigd. De status is behouden en er is geen e-mail verstuurd.",result.code==="SUCCESS",false);}catch(caught){if(caught instanceof BookingVisitDateApiError&&caught.result){const result=caught.result;if(result.code==="OVERRIDE_REQUIRED"||result.code==="INVALID_OVERRIDE_REQUEST"){issues.value=result.validationIssues.filter(i=>i.overridable);const next:Record<string,string>={};for(const issue of issues.value)next[issue.code]=reasons.value[issue.code]??"";reasons.value=next;dialog.value=true;return;}if(result.code==="VISIT_DATE_CONFLICT"){emit("completed","De bezoekdatum is inmiddels door iemand anders gewijzigd. De actuele datum is geladen; controleer uw gekozen datum opnieuw.",true,true);return;}const dateIssue=result.validationIssues.find(i=>i.field==="bezoekdatum"||i.field==="programma");error.value=dateIssue?.description??(result.code==="INVALID_VISIT_DATE"?"Kies een geldige bezoekdatum.":"De bezoekdatum kon niet worden gewijzigd.");}else error.value="De bezoekdatum kon niet worden gewijzigd.";}finally{submitting.value=false;}}
</script>

<template>
  <section class="admin-card admin-visit-date-panel">
    <h2>Bezoekdatum</h2>
    <template v-if="!editing"><p class="admin-visit-date-panel__date">{{ formatDate(visitDate) }}</p><AdminButton :disabled="refreshing" @click="open">Wijzigen</AdminButton></template>
    <form v-else class="admin-visit-date-panel__form" @submit.prevent="save(false)">
      <dl class="admin-details"><dt>Huidige datum</dt><dd>{{ formatDate(visitDate) }}</dd><dt>Status</dt><dd>{{ status }} (blijft ongewijzigd)</dd><dt>Programma</dt><dd>{{ programLabel }}</dd></dl>
      <div v-if="!calendarError" class="admin-booking-calendar" :aria-busy="calendarLoading">
        <div class="admin-booking-calendar__header"><button type="button" aria-label="Vorige maand" @click="changeMonth(-1)"><ChevronLeft aria-hidden="true"/></button><h3 aria-live="polite">{{ monthLabel }}</h3><button type="button" aria-label="Volgende maand" @click="changeMonth(1)"><ChevronRight aria-hidden="true"/></button></div>
        <div class="admin-booking-calendar__weekdays" aria-hidden="true"><span>Ma</span><span>Di</span><span>Wo</span><span>Do</span><span>Vr</span><span>Za</span><span>Zo</span></div>
        <p v-if="calendarLoading&&!days.length" class="admin-booking-calendar__loading" role="status">Beschikbaarheid laden…</p>
        <div v-else class="admin-booking-calendar__grid" role="grid" aria-label="Beschikbaarheid per bezoekdatum">
          <button v-for="day in days" :key="day.date" type="button" role="gridcell" :data-calendar-date="day.date" class="admin-booking-calendar__day" :class="[`is-${day.state}`,{'is-outside':monthStart(day.date)!==visibleMonth,'is-selected':day.date===proposed,'is-current':day.isCurrentVisitDate,'is-today':day.date===today}]" :disabled="!day.selectable" :aria-disabled="!day.selectable" :aria-selected="day.date===proposed" :aria-label="ariaLabel(day)" @click="selectDay(day)" @focus="focusedDate=day.date" @mouseenter="focusedDate=day.date" @keydown="keydown($event,day)">
            <span>{{ Number(day.date.slice(-2)) }}</span><small v-if="day.isCurrentVisitDate">Huidig</small><TriangleAlert v-if="day.state==='warning'||day.state==='override_required'" :size="13" aria-hidden="true"/>
            <span class="admin-booking-calendar__tooltip" role="tooltip">{{ ariaLabel(day) }}</span>
          </button>
        </div>
        <div class="admin-booking-calendar__legend" aria-label="Legenda"><span class="is-available">Beschikbaar</span><span class="is-warning">Waarschuwing</span><span class="is-override_required">Override nodig</span><span class="is-blocked">Niet beschikbaar</span><span class="is-current">Huidige datum</span></div>
        <section v-if="focusedDay" class="admin-booking-calendar__detail" aria-live="polite"><h4>{{ formatDate(focusedDay.date) }}</h4><p>{{ focusedDay.capacity.confirmedSchools }} van {{ focusedDay.capacity.schoolLimit }} scholen definitief</p><p>{{ focusedDay.capacity.confirmedStudents }} van {{ focusedDay.capacity.studentLimit }} leerlingen</p><p>{{ programLabel }}: {{ focusedDay.capacity.confirmedProgramStudents }} van {{ focusedDay.capacity.programStudentLimit ?? "—" }}</p><strong>{{ dayStateLabel(focusedDay) }}</strong><ul v-if="focusedDay.reasons.length"><li v-for="reason in focusedDay.reasons" :key="reason.code">{{ reason.title }} — {{ reason.description }}</li></ul></section>
      </div>
      <div v-else class="admin-booking-calendar__error" role="alert"><p>De beschikbaarheidskalender kon niet worden geladen. U kunt het bestaande datumveld blijven gebruiken; de server controleert de datum bij opslaan.</p><AdminButton type="button" variant="secondary" @click="loadCalendar(true)">Opnieuw proberen</AdminButton><AdminDateField v-model="proposed" label="Nieuwe bezoekdatum" name="visit-date-fallback" required :disabled="submitting||refreshing" :error="error"/></div>
      <p v-if="error&&!calendarError" class="admin-field__message admin-field__message--error" role="alert">{{ error }}</p>
      <div class="admin-visit-date-panel__actions"><AdminButton variant="secondary" :disabled="submitting" @click="cancel">Annuleren</AdminButton><AdminButton type="submit" :loading="submitting" :disabled="!proposed||refreshing">Opslaan</AdminButton></div>
    </form>
    <BookingRuleOverrideDialog :open="dialog" :issues="issues" :submitting="submitting" :reasons="reasons" @close="dialog=false" @update:reason="(code,value)=>reasons[code]=value" @confirm="save(true)"/>
  </section>
</template>
