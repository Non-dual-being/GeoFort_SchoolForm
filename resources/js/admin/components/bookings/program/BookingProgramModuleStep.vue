<script setup lang="ts">
import AdminChoiceCard from "../../form/AdminChoiceCard.vue";
import AdminChoiceCardGrid from "../../form/AdminChoiceCardGrid.vue";
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../../form/AdminInlineNotice.vue";
import AdminButton from "../../form/AdminButton.vue";
type Module={key:string;label:string};
withDefaults(defineProps<{modelValue:string|null;options:Module[];confirmed:boolean;confirmationError?:string|null;fieldError?:string|null;staleMessage?:string|null}>(),{confirmationError:null,fieldError:null,staleMessage:null});
const emit=defineEmits<{"update:modelValue":[value:string|null];"update:confirmed":[value:boolean];"edit-program":[];"edit-education":[]}>();
</script>
<template>
  <section class="admin-program-step" aria-labelledby="module-step-title">
    <header><p class="admin-program-step__eyebrow">Stap 4 van 5</p><h3 id="module-step-title">Kies een keuzemodule</h3><p>De beschikbare keuzemodules worden aangepast aan uw onderwijsselectie.</p></header>
    <AdminInlineNotice v-if="staleMessage" variant="info">{{ staleMessage }}</AdminInlineNotice>
    <AdminInlineNotice v-if="fieldError" variant="error" title="Controleer de keuzemodule">{{ fieldError }}</AdminInlineNotice>
    <AdminInlineNotice v-if="options.length===0" variant="error" title="Geen keuzemodule beschikbaar"><p>Voor deze combinatie van programma, niveau en groepen is geen keuzemodule beschikbaar. Pas de selectie aan.</p><div class="admin-wizard-actions__secondary"><AdminButton type="button" variant="secondary" @click="emit('edit-program')">Programma aanpassen</AdminButton><AdminButton type="button" variant="secondary" @click="emit('edit-education')">Onderwijsselectie aanpassen</AdminButton></div></AdminInlineNotice>
    <AdminChoiceCardGrid v-else><AdminChoiceCard v-for="module in options" :key="module.key" :model-value="modelValue" :value="module.key" :label="module.label" name="program-module" @update:model-value="value=>emit('update:modelValue',value)"/></AdminChoiceCardGrid>
    <AdminButton v-if="modelValue" type="button" variant="ghost" @click="emit('update:modelValue',null)">Keuze wissen</AdminButton>
    <AdminInlineNotice v-if="options.length>0&&!modelValue" variant="info">Kies een keuzemodule die past bij het programma en de geselecteerde groepen.</AdminInlineNotice>
    <AdminConfirmationControl :model-value="confirmed" label="Ik heb de keuzemodule gecontroleerd" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/>
  </section>
</template>
