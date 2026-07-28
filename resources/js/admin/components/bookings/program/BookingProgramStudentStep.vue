<script setup lang="ts">
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminNumberControl from "../../form/AdminNumberControl.vue";
withDefaults(defineProps<{modelValue:number|null;confirmed:boolean;stepNumber:number;totalSteps:number;confirmationError?:string|null;fieldError?:string|null;minimum:number;maximum:number}>(),{confirmationError:null,fieldError:null});
const emit=defineEmits<{"update:modelValue":[value:number|null];"update:confirmed":[value:boolean]}>();
</script>
<template><section class="admin-program-step" aria-labelledby="student-step-title"><header><p class="admin-program-step__eyebrow">Stap {{ stepNumber }} van {{ totalSteps }}</p><h3 id="student-step-title">Controleer het leerlingenaantal</h3><p>Minimaal {{ minimum }}, maximaal {{ maximum }} leerlingen.</p></header><AdminNumberControl :model-value="modelValue" label="Aantal leerlingen" :description="`Minimaal ${minimum}, maximaal ${maximum} leerlingen.`" :min="minimum" :max="maximum" :step="1" unit-label="leerlingen" :error="fieldError" @update:model-value="value=>emit('update:modelValue',value)"/><AdminConfirmationControl :model-value="confirmed" label="Ik heb het leerlingenaantal gecontroleerd" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/></section></template>
