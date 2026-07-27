<script setup lang="ts">
import { Check } from "lucide-vue-next";
export type AdminWizardStepState="current"|"completed"|"available"|"upcoming"|"error"|"disabled";
export interface AdminWizardStep{label:string;state:AdminWizardStepState;error?:boolean}
defineProps<{steps:readonly AdminWizardStep[]}>();
const emit=defineEmits<{select:[step:number]}>();
function selectable(state:AdminWizardStepState):boolean{return state==="completed"||state==="available";}
</script>
<template>
  <nav class="admin-wizard-stepper" aria-label="Voortgang">
    <ol class="admin-wizard-stepper__list">
      <li v-for="(item,index) in steps" :key="item.label" class="admin-wizard-stepper__item">
        <button class="admin-wizard-stepper__step" :class="[`admin-wizard-stepper__step--${item.state}`,{'admin-wizard-stepper__step--error':item.error}]" type="button" :disabled="!selectable(item.state)" :aria-current="item.state==='current'?'step':undefined" :aria-invalid="item.error||undefined" :aria-label="`Stap ${index+1}: ${item.label}, ${item.error?'fout':item.state}`" @click="emit('select',index+1)">
          <span class="admin-wizard-stepper__marker" aria-hidden="true"><Check v-if="item.state==='completed'" :size="17"/><span v-else>{{ index+1 }}</span></span>
          <span class="admin-wizard-stepper__label">{{ item.label }}</span>
        </button>
      </li>
    </ol>
  </nav>
</template>
