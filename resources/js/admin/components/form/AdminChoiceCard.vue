<script setup lang="ts">
import {computed,useId} from "vue";
import {Circle,CheckCircle2} from "lucide-vue-next";
const props=withDefaults(defineProps<{modelValue:string|null;value:string|null;label:string;description?:string|string[];meta?:string;disabled?:boolean;error?:boolean;name:string}>(),{description:undefined,meta:undefined,disabled:false,error:false});
const emit=defineEmits<{"update:modelValue":[value:string|null];change:[value:string|null]}>();
const id=`admin-choice-${useId()}`;const selected=computed(()=>props.modelValue===props.value);
function select():void{if(props.disabled)return;emit("update:modelValue",props.value);emit("change",props.value);}
</script>
<template><label class="admin-choice-card" :class="{'admin-choice-card--selected':selected,'admin-choice-card--disabled':disabled,'admin-choice-card--error':error}" :for="id"><input :id="id" class="admin-choice-card__input" type="radio" :name="name" :value="value??''" :checked="selected" :disabled="disabled" :aria-invalid="error||undefined" @change="select"><span class="admin-choice-card__indicator" aria-hidden="true"><CheckCircle2 v-if="selected" :size="21"/><Circle v-else :size="21"/></span><span class="admin-choice-card__body"><strong>{{ label }}</strong><small v-for="line in Array.isArray(description)?description:description?[description]:[]" :key="line">{{ line }}</small><small v-if="meta" class="admin-choice-card__meta">{{ meta }}</small></span></label></template>
