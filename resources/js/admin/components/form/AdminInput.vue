<script setup lang="ts">
import { computed, useId } from "vue";

const props=withDefaults(defineProps<{modelValue:string;label:string;name?:string;type?:string;inputmode?:"text"|"email"|"tel"|"numeric";autocomplete?:string;placeholder?:string;required?:boolean;disabled?:boolean;error?:string|null}>(),{name:undefined,type:"text",inputmode:"text",autocomplete:"off",placeholder:"",required:false,disabled:false,error:null});
const emit=defineEmits<{"update:modelValue":[value:string];blur:[]}>();
const generatedId=useId();
const id=computed(()=>props.name?`admin-input-${props.name}`:`admin-input-${generatedId}`);
const errorId=computed(()=>`${id.value}-error`);
defineExpose({focus:()=>document.getElementById(id.value)?.focus()});
</script>
<template>
  <div class="admin-field" :class="{'admin-field--invalid':Boolean(error),'admin-field--disabled':disabled}">
    <label class="admin-field__label" :for="id">{{ label }}<span v-if="required" class="admin-field__required" aria-hidden="true"> *</span></label>
    <input :id="id" class="admin-control admin-text-input" :name="name" :type="type" :inputmode="inputmode" :autocomplete="autocomplete" :placeholder="placeholder" :required="required" :disabled="disabled" :value="modelValue" :aria-invalid="error?'true':undefined" :aria-describedby="error?errorId:undefined" @input="emit('update:modelValue',($event.target as HTMLInputElement).value)" @blur="emit('blur')">
    <p v-if="error" :id="errorId" class="admin-field__message admin-field__message--error">{{ error }}</p>
  </div>
</template>
