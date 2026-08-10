<script setup lang="ts">
import {computed,useId} from "vue";
import {Minus,Plus} from "lucide-vue-next";
import AdminSelectionHint from "./AdminSelectionHint.vue";
const props=withDefaults(defineProps<{modelValue:number|null;label:string;description?:string;min?:number;max?:number;step?:number;unitLabel?:string;error?:string|null;disabled?:boolean;controls?:boolean;inputMode?:"numeric"|"decimal"}>(),{description:undefined,min:undefined,max:undefined,step:1,unitLabel:undefined,error:null,disabled:false,controls:true,inputMode:"numeric"});
const emit=defineEmits<{"update:modelValue":[value:number|null];change:[value:number|null]}>();
const id=`admin-number-${useId()}`;
const help=computed(()=>[props.description,props.min!==undefined?`Minimum ${props.min}`:null,props.max!==undefined?`Maximum ${props.max}`:null].filter(Boolean).join(" · "));
const atMinimum=computed(()=>props.modelValue!==null&&props.min!==undefined&&props.modelValue<=props.min);
const atMaximum=computed(()=>props.modelValue!==null&&props.max!==undefined&&props.modelValue>=props.max);
function update(value:number):void{if(!Number.isFinite(value))return;const next=Math.min(props.max??Infinity,Math.max(props.min??-Infinity,value));emit("update:modelValue",next);emit("change",next);}
function input(event:Event):void{const element=event.target as HTMLInputElement;if(element.value===""){emit("update:modelValue",null);emit("change",null);return;}update(element.valueAsNumber);}
</script>
<template>
  <div class="admin-field" :class="{'admin-field--invalid':error,'admin-field--disabled':disabled}">
    <label class="admin-field__label" :for="id">{{ label }}</label>
    <div class="admin-number-control">
      <span class="admin-number-control__action" :class="{'admin-number-control__action--limit':atMinimum}">
        <button v-if="controls" type="button" :disabled="disabled||modelValue===null||atMinimum" :aria-label="`${label} verlagen`" @click="modelValue!==null&&update(modelValue-step)"><Minus :size="18" aria-hidden="true"/></button>
        <AdminSelectionHint v-if="controls&&atMinimum&&min!==undefined" :message="`Het minimum van ${min} ${unitLabel??''} is bereikt.`" :label="`Waarom kan ${label} niet verder worden verlaagd?`" variant="limit"/>
      </span>
      <div class="admin-number-control__input"><input :id="id" class="admin-control" type="number" inputmode="numeric" v-bind="inputMode === 'decimal' ? { inputmode: 'decimal' } : {}" :value="modelValue??''" :min="min" :max="max" :step="step" :disabled="disabled" :aria-invalid="error?'true':undefined" :aria-describedby="help||error||atMinimum||atMaximum?`${id}-description`:undefined" @input="input"><span v-if="unitLabel">{{ unitLabel }}</span></div>
      <span class="admin-number-control__action" :class="{'admin-number-control__action--limit':atMaximum}">
        <button v-if="controls" type="button" :disabled="disabled||modelValue===null||atMaximum" :aria-label="`${label} verhogen`" @click="modelValue!==null&&update(modelValue+step)"><Plus :size="18" aria-hidden="true"/></button>
        <AdminSelectionHint v-if="controls&&atMaximum&&max!==undefined" :message="`Het maximum van ${max} ${unitLabel??''} is bereikt.`" :label="`Waarom kan ${label} niet verder worden verhoogd?`" variant="limit"/>
      </span>
    </div>
    <p v-if="error" :id="`${id}-description`" class="admin-field__message admin-field__message--error" role="alert">{{ error }}</p>
    <p v-else :id="`${id}-description`" class="admin-field__message" :class="{'admin-selection-count--limit':atMinimum||atMaximum}"><template v-if="atMinimum">Het minimum is bereikt. </template><template v-else-if="atMaximum">Het maximum is bereikt. </template>{{ help }}</p>
  </div>
</template>
