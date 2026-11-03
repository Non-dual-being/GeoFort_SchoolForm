<script setup lang="ts">
import AdminChoiceCard from "../../form/AdminChoiceCard.vue";
import AdminChoiceCardGrid from "../../form/AdminChoiceCardGrid.vue";
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../../form/AdminInlineNotice.vue";
type Option={key:string;label:string;description:string[];availableOnVisitDate?:boolean};
withDefaults(defineProps<{modelValue:string;options:Option[];confirmed:boolean;stepNumber:number;totalSteps:number;moduleChangeNotice?:string|null;confirmationError?:string|null;fieldError?:string|null;sectorMismatch:boolean;weekdayMismatch:boolean;weekdayAccepted:boolean}>(),{confirmationError:null,fieldError:null,moduleChangeNotice:null});
const emit=defineEmits<{"update:modelValue":[value:string];"update:confirmed":[value:boolean];"update:weekdayAccepted":[value:boolean]}>();
</script>
<template>
  <section class="admin-program-step" aria-labelledby="program-step-title">
    <header><p class="admin-program-step__eyebrow">Stap {{ stepNumber }} van {{ totalSteps }}</p><h3 id="program-step-title">Kies het programma</h3><p>Selecteer het programma dat bij deze aanvraag moet worden vastgelegd.</p></header>
    <AdminInlineNotice v-if="moduleChangeNotice" variant="info">{{ moduleChangeNotice }}</AdminInlineNotice>
    <AdminInlineNotice v-if="fieldError" variant="error" title="Controleer het programma">{{ fieldError }}</AdminInlineNotice>
    <AdminChoiceCardGrid><AdminChoiceCard v-for="option in options" :key="option.key" :model-value="modelValue" :value="option.key" :label="option.label" :description="option.description" name="program-configuration" :disabled="option.availableOnVisitDate === false" :meta="option.availableOnVisitDate === false ? 'Niet opengesteld op deze datum' : undefined" :error="Boolean(fieldError)&&(!modelValue||modelValue===option.key)||sectorMismatch&&modelValue===option.key" @update:model-value="value=>emit('update:modelValue',String(value))"/></AdminChoiceCardGrid>
    <AdminInlineNotice v-if="sectorMismatch" variant="error" title="Niet beschikbaar">Dit programma is niet beschikbaar voor deze schoolsector.</AdminInlineNotice>
    <AdminInlineNotice v-if="weekdayMismatch&&!sectorMismatch" variant="warning" title="Niet opengesteld"><p>Dit programma is niet opengesteld op de bezoekdatum. Pas de beschikbaarheid aan bij Configuratie of kies een andere datum.</p></AdminInlineNotice>
    <AdminConfirmationControl :model-value="confirmed" label="Ik heb het programma gecontroleerd" :disabled="sectorMismatch||weekdayMismatch" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/>
  </section>
</template>
