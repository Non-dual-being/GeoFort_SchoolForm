<script setup lang="ts">
import {CheckCircle2,Circle} from "lucide-vue-next";
import AdminConfirmationControl from "../../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../../form/AdminInlineNotice.vue";
import AdminOptionChip from "../../form/AdminOptionChip.vue";
import AdminSelectionHint from "../../form/AdminSelectionHint.vue";
import type {EducationSelectionValue} from "../../../types/bookingProgramConfiguration";
import type {ProgramConfigurationFrontendIssue} from "../../../validation/programConfigurationStepValidator";

type Level={label:string;groups:Record<string,string>};
type Rules={minLevels:number;maxLevels:number;minGroupsPerLevel:number;maxGroupsPerLevel:number};
const props=withDefaults(defineProps<{modelValue:EducationSelectionValue;levels:Record<string,Level>;rules:Rules;attempted:boolean;issues:ProgramConfigurationFrontendIssue[];confirmed:boolean;stepNumber:number;totalSteps:number;confirmationError?:string|null;fieldError?:string|null}>(),{confirmationError:null,fieldError:null});
const emit=defineEmits<{"update:modelValue":[value:EducationSelectionValue];"update:confirmed":[value:boolean]}>();

function levelDisabled(level:string):boolean{return !props.modelValue.selectedLevels.includes(level)&&props.modelValue.selectedLevels.length>=props.rules.maxLevels;}
function groupDisabled(level:string,group:string):boolean{const groups=props.modelValue.selectedGroupsByLevel[level]??[];return !groups.includes(group)&&groups.length>=props.rules.maxGroupsPerLevel;}
function levelIssue(level:string):ProgramConfigurationFrontendIssue|undefined{return props.issues.find(issue=>issue.level===level);}
function generalIssue():ProgramConfigurationFrontendIssue|undefined{return props.issues.find(issue=>(!issue.level||!props.levels[issue.level])&&issue.code!=="STEP_CONFIRMATION_REQUIRED");}
function levelLimitMessage(levelLabel:string):string{return`U heeft het maximum van ${props.rules.maxLevels} niveaus geselecteerd. Verwijder eerst een niveau om ${levelLabel} te kunnen kiezen.`;}
function groupLimitMessage(levelLabel:string,groupLabel:string):string{return`Binnen ${levelLabel} zijn maximaal ${props.rules.maxGroupsPerLevel} groepen toegestaan. Verwijder eerst een groep om ${groupLabel} te kunnen kiezen.`;}
function levelCountText():string{const count=props.modelValue.selectedLevels.length,max=props.rules.maxLevels;if(count>=max)return`${count} van ${max} niveaus geselecteerd. Verwijder een niveau om een ander niveau te kiezen.`;return`${count} van ${max} niveaus geselecteerd.`;}
function groupCountText(level:string):string{const count=props.modelValue.selectedGroupsByLevel[level]?.length??0,max=props.rules.maxGroupsPerLevel;return count>=max?`${count} van ${max} groepen geselecteerd. Het maximum is bereikt.`:`${count} van ${max} groepen geselecteerd.`;}
function toggleLevel(level:string):void{
  const selected=props.modelValue.selectedLevels.includes(level);
  if(!selected&&levelDisabled(level))return;
  const selectedLevels=selected?props.modelValue.selectedLevels.filter(item=>item!==level):[...props.modelValue.selectedLevels,level];
  const selectedGroupsByLevel=Object.fromEntries(Object.entries(props.modelValue.selectedGroupsByLevel).filter(([key])=>selectedLevels.includes(key)));
  emit("update:modelValue",{...props.modelValue,selectedLevels,selectedGroupsByLevel});
}
function toggleGroup(level:string,group:string):void{
  const groups=props.modelValue.selectedGroupsByLevel[level]??[];
  if(!groups.includes(group)&&groupDisabled(level,group))return;
  emit("update:modelValue",{...props.modelValue,selectedGroupsByLevel:{...props.modelValue.selectedGroupsByLevel,[level]:groups.includes(group)?groups.filter(item=>item!==group):[...groups,group]}});
}
</script>
<template>
  <section class="admin-program-step" aria-labelledby="education-step-title">
    <header><p class="admin-program-step__eyebrow">Stap {{ stepNumber }} van {{ totalSteps }}</p><h3 id="education-step-title">Controleer niveaus en groepen</h3></header>
    <AdminInlineNotice variant="info">Selecteer minimaal {{ rules.minLevels }} en maximaal {{ rules.maxLevels }} niveau{{ rules.maxLevels===1?"":"s" }}. Selecteer per niveau minimaal {{ rules.minGroupsPerLevel }} en maximaal {{ rules.maxGroupsPerLevel }} groepen.</AdminInlineNotice>
    <p id="education-level-maximum" class="admin-selection-count" :class="{'admin-selection-count--complete':modelValue.selectedLevels.length>=rules.minLevels,'admin-selection-count--limit':modelValue.selectedLevels.length>=rules.maxLevels}">{{ levelCountText() }}</p>
    <AdminInlineNotice v-if="attempted&&generalIssue()" variant="error" title="Controleer de niveaus">{{ generalIssue()?.message }}</AdminInlineNotice>
    <AdminInlineNotice v-if="fieldError" variant="error" title="Controleer de onderwijsselectie">{{ fieldError }}</AdminInlineNotice>
    <div class="admin-education-grid">
      <article v-for="(level,levelKey) in levels" :key="levelKey" class="admin-level-card" :class="{'admin-level-card--selected':modelValue.selectedLevels.includes(String(levelKey)),'admin-level-card--error':Boolean(levelIssue(String(levelKey)))}">
        <div class="admin-level-card__header-row" :class="{'admin-level-card__header-row--temporarily-disabled':levelDisabled(String(levelKey))}">
          <label class="admin-level-card__header">
            <input type="checkbox" :checked="modelValue.selectedLevels.includes(String(levelKey))" :disabled="levelDisabled(String(levelKey))" :aria-disabled="levelDisabled(String(levelKey))||undefined" :aria-describedby="levelDisabled(String(levelKey))?'education-level-maximum':undefined" @change="toggleLevel(String(levelKey))">
            <CheckCircle2 v-if="modelValue.selectedLevels.includes(String(levelKey))" :size="21" aria-hidden="true"/><Circle v-else :size="21" aria-hidden="true"/><strong>{{ level.label }}</strong>
          </label>
          <AdminSelectionHint v-if="levelDisabled(String(levelKey))" :message="levelLimitMessage(level.label)" :label="`Waarom kan ${level.label} niet worden geselecteerd?`" variant="limit"/>
        </div>
        <div v-if="modelValue.selectedLevels.includes(String(levelKey))" class="admin-level-card__groups">
          <p :id="`group-maximum-${String(levelKey)}`" class="admin-selection-count admin-level-card__selection-count" :class="{'admin-selection-count--complete':(modelValue.selectedGroupsByLevel[String(levelKey)]??[]).length>=rules.minGroupsPerLevel,'admin-selection-count--limit':(modelValue.selectedGroupsByLevel[String(levelKey)]??[]).length>=rules.maxGroupsPerLevel}">{{ groupCountText(String(levelKey)) }}</p>
          <div class="admin-level-card__group-options"><AdminOptionChip v-for="(groupLabel,groupKey) in level.groups" :key="groupKey" :label="groupLabel" :selected="(modelValue.selectedGroupsByLevel[String(levelKey)]??[]).includes(String(groupKey))" :disabled="groupDisabled(String(levelKey),String(groupKey))" :disabled-message="groupLimitMessage(level.label,groupLabel)" :help-label="`Waarom kan ${groupLabel} niet worden geselecteerd?`" :error="Boolean(levelIssue(String(levelKey)))" :described-by="groupDisabled(String(levelKey),String(groupKey))?`group-maximum-${String(levelKey)}`:undefined" @toggle="toggleGroup(String(levelKey),String(groupKey))"/></div>
          <AdminInlineNotice v-if="attempted&&levelIssue(String(levelKey))" variant="error">{{ levelIssue(String(levelKey))?.message }}</AdminInlineNotice>
        </div>
        <p v-else class="admin-level-card__hint">Selecteer dit niveau om groepen te kiezen.</p>
        <AdminInlineNotice v-if="attempted&&levelIssue(String(levelKey))&&!modelValue.selectedLevels.includes(String(levelKey))" variant="error">{{ levelIssue(String(levelKey))?.message }}</AdminInlineNotice>
      </article>
    </div>
    <AdminConfirmationControl :model-value="confirmed" label="Ik heb niveaus en groepen gecontroleerd" :error="confirmationError" @update:model-value="value=>emit('update:confirmed',value)"/>
  </section>
</template>
