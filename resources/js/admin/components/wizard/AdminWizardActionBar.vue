<script setup lang="ts">
import AdminButton from "../form/AdminButton.vue";
const props=withDefaults(defineProps<{step:number;totalSteps:number;nextDisabled?:boolean;submitting?:boolean;submitLabel?:string}>(),{nextDisabled:false,submitting:false,submitLabel:"Configuratie opslaan"});
const emit=defineEmits<{back:[];cancel:[];next:[];submit:[]}>();
</script>
<template>
  <div class="admin-wizard-actions">
    <div class="admin-wizard-actions__secondary">
      <AdminButton v-if="props.step>1" variant="secondary" :disabled="submitting" @click="emit('back')">Terug</AdminButton>
      <AdminButton variant="ghost" :disabled="submitting" @click="emit('cancel')">Annuleren</AdminButton>
    </div>
    <AdminButton v-if="props.step<props.totalSteps" class="admin-wizard-actions__primary" :disabled="nextDisabled||submitting" @click="emit('next')">Volgende</AdminButton>
    <AdminButton v-else class="admin-wizard-actions__primary" type="button" :loading="submitting" :disabled="nextDisabled||submitting" @click="emit('submit')">{{ submitLabel }}</AdminButton>
  </div>
</template>
