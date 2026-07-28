<script setup lang="ts">
import AdminChoiceCard from "../../form/AdminChoiceCard.vue";
import AdminChoiceCardGrid from "../../form/AdminChoiceCardGrid.vue";
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../../form/AdminInlineNotice.vue";
type Option={key:string;label:string;description:string[]};
withDefaults(defineProps<{modelValue:string;options:Option[];confirmed:boolean;stepNumber:number;totalSteps:number;moduleChangeNotice?:string|null;confirmationError?:string|null;fieldError?:string|null;sectorMismatch:boolean;weekdayMismatch:boolean;weekdayAccepted:boolean}>(),{confirmationError:null,fieldError:null,moduleChangeNotice:null});
const emit=defineEmits<{"update:modelValue":[value:string];"update:confirmed":[value:boolean];"update:weekdayAccepted":[value:boolean]}>();
</script>
<template>
  <section class="admin-program-step" aria-labelledby="program-step-title">
    <header><p class="admin-program-step__eyebrow">Stap {{ stepNumber }} van {{ totalSteps }}</p><h3 id="program-step-title">Kies het programma</h3><p>Selecteer het programma dat bij deze aanvraag moet worden vastgelegd.</p></header>
    <AdminInlineNotice v-if="moduleChangeNotice" variant="info">{{ moduleChangeNotice }}</AdminInlineNotice>
    <AdminInlineNotice v-if="fieldError" variant="error" title="Controleer het programma">{{ fieldError }}</AdminInlineNotice>
    <AdminChoiceCardGrid><AdminChoiceCard v-for="option in options" :key="option.key" :model-value="modelValue" :value="option.key" :label="option.label" :description="option.description" name="program-configuration" :error="Boolean(fieldError)&&(!modelValue||modelValue===option.key)||sectorMismatch&&modelValue===option.key" @update:model-value="value=>emit('update:modelValue',String(value))"/></AdminChoiceCardGrid>
    <AdminInlineNotice v-if="sectorMismatch" variant="error" title="Niet beschikbaar">Dit programma is niet beschikbaar voor deze schoolsector.</AdminInlineNotice>
    <AdminInlineNotice v-if="weekdayMismatch&&!sectorMismatch" variant="warning" title="Afwijkende weekdag"><p>Het Ochtendprogramma is normaal alleen op woensdag. Deze keuze wordt apart geaudit.</p><AdminConfirmationControl :model-value="weekdayAccepted" label="Afwijken van de normale weekdag" @update:model-value="value=>emit('update:weekdayAccepted',value)"/></AdminInlineNotice>
    <AdminConfirmationControl :model-value="confirmed" label="Ik heb het programma gecontroleerd" :disabled="sectorMismatch||weekdayMismatch&&!weekdayAccepted" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/>
  </section>
</template>
