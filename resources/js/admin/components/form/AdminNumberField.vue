<script setup lang="ts">
import { computed, useId } from "vue";
const props = withDefaults(defineProps<{modelValue:number|null;label:string;name?:string;min?:number;max?:number;disabled?:boolean;error?:string|null}>(),{name:undefined,min:undefined,max:undefined,disabled:false,error:null});
const emit=defineEmits<{"update:modelValue":[value:number|null]}>();const generatedId=useId();
const id=computed(()=>props.name?`admin-number-${props.name}`:`admin-number-${generatedId}`);
function update(event:Event):void{const value=(event.target as HTMLInputElement).value;emit("update:modelValue",value===""?null:Number(value));}
</script>
<template><div class="admin-field" :class="{'admin-field--invalid':Boolean(error),'admin-field--disabled':disabled}"><label class="admin-field__label" :for="id">{{ label }}</label><input :id="id" class="admin-control admin-number-field__control" type="number" inputmode="numeric" step="1" :name="name" :value="modelValue??''" :min="min" :max="max" :disabled="disabled" :aria-invalid="error?'true':undefined" @input="update"><p v-if="error" class="admin-field__message admin-field__message--error" role="alert">{{ error }}</p></div></template>
