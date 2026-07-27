<script setup lang="ts">
import {useId} from "vue";import {CheckCircle2,Circle} from "lucide-vue-next";
withDefaults(defineProps<{modelValue:boolean;label:string;description?:string;disabled?:boolean;error?:string|null}>(),{description:undefined,disabled:false,error:null});
const emit=defineEmits<{"update:modelValue":[value:boolean]}>();const id=`admin-confirm-${useId()}`;
</script>
<template><div><label class="admin-confirmation" :class="{'admin-confirmation--checked':modelValue,'admin-confirmation--disabled':disabled,'admin-confirmation--error':error}" :for="id"><input :id="id" class="admin-confirmation__input" type="checkbox" :checked="modelValue" :disabled="disabled" :aria-invalid="error?'true':undefined" :aria-describedby="error?`${id}-error`:undefined" @change="emit('update:modelValue',($event.target as HTMLInputElement).checked)"><CheckCircle2 v-if="modelValue" :size="22" aria-hidden="true"/><Circle v-else :size="22" aria-hidden="true"/><span><strong>{{ label }}</strong><small v-if="description">{{ description }}</small></span></label><p v-if="error" :id="`${id}-error`" class="admin-field__message admin-field__message--error" role="alert">{{ error }}</p></div></template>
