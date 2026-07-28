<script setup lang="ts">
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../../form/AdminInlineNotice.vue";
import type {ProgramConfigurationExpected,ProgramConfigurationProposed} from "../../../types/bookingProgramConfiguration";
import type {ProgramConfigurationStepId} from "../../../validation/programConfigurationStepValidator";
type Level={label:string;groups:Record<string,string>};
const props=withDefaults(defineProps<{expected:ProgramConfigurationExpected;proposed:ProgramConfigurationProposed;programLabel:string;moduleLabel:string;visitDateLabel:string;schoolSectorLabel:string;levels:Record<string,Level>;confirmed:boolean;stepNumber:number;totalSteps:number;supportsChoiceModules:boolean;moduleChangeNotice?:string|null;confirmationError?:string|null}>(),{confirmationError:null,moduleChangeNotice:null});
const emit=defineEmits<{"update:confirmed":[value:boolean];edit:[stepId:ProgramConfigurationStepId]}>();
function levelLabel(key:string):string{return props.levels[key]?.label??key;}
function groupLabels(level:string):string{return(props.proposed.educationSelection.selectedGroupsByLevel[level]??[]).map(key=>props.levels[level]?.groups[key]??key).join(", ");}
function changed(before:unknown,after:unknown):boolean{return JSON.stringify(before)!==JSON.stringify(after);}
</script>
<template>
  <section class="admin-program-step" aria-labelledby="review-step-title">
    <header><p class="admin-program-step__eyebrow">Stap {{ stepNumber }} van {{ totalSteps }}</p><h3 id="review-step-title">Controleer de volledige configuratie</h3><p>Bekijk alle waarden voordat je de wijziging transactioneel opslaat.</p></header>
    <AdminInlineNotice v-if="moduleChangeNotice" variant="info">{{ moduleChangeNotice }}</AdminInlineNotice>
    <div class="admin-review-grid">
      <article class="admin-review-card"><header><h4>Programma</h4><button type="button" @click="emit('edit','program')">Aanpassen</button></header><p>{{ programLabel }}</p><small v-if="changed(expected.program,proposed.program)">Was: {{ expected.program }}</small></article>
      <article class="admin-review-card"><header><h4>Leerlingenaantal</h4><button type="button" @click="emit('edit','students')">Aanpassen</button></header><p>{{ proposed.studentCount }} leerlingen</p><small v-if="expected.studentCount!==proposed.studentCount">Was: {{ expected.studentCount }}</small></article>
      <article class="admin-review-card"><header><h4>Onderwijs</h4><button type="button" @click="emit('edit','education')">Aanpassen</button></header><div v-for="level in proposed.educationSelection.selectedLevels" :key="level"><p>{{ levelLabel(level) }}</p><small>{{ groupLabels(level) }}</small></div></article>
      <article v-if="supportsChoiceModules" class="admin-review-card"><header><h4>Keuzemodule</h4><button type="button" @click="emit('edit','module')">Aanpassen</button></header><p>{{ moduleLabel }}</p><small v-if="changed(expected.choiceModule,proposed.choiceModule)">Was: {{ expected.choiceModule??"Geen" }}</small></article>
      <article class="admin-review-card"><h4>Bezoekdatum</h4><p>{{ visitDateLabel }}</p><small>Blijft ongewijzigd</small></article>
      <article class="admin-review-card"><h4>Schoolsector</h4><p>{{ schoolSectorLabel }}</p><small>Blijft ongewijzigd</small></article>
      <article class="admin-review-card"><h4>Status</h4><p>{{ expected.status }}</p><small>Blijft ongewijzigd</small></article>
    </div>
    <AdminConfirmationControl :model-value="confirmed" label="Ik bevestig de volledige programmaconfiguratie" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/>
  </section>
</template>
