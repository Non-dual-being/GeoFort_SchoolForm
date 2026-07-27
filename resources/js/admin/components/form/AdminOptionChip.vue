<script setup lang="ts">
import {Check} from "lucide-vue-next";
import AdminSelectionHint from "./AdminSelectionHint.vue";
withDefaults(defineProps<{selected:boolean;label:string;disabled?:boolean;error?:boolean;describedBy?:string;disabledMessage?:string;helpLabel?:string}>(),{disabled:false,error:false,describedBy:undefined,disabledMessage:undefined,helpLabel:undefined});
const emit=defineEmits<{toggle:[]}>();
</script>
<template><span class="admin-option-chip-wrap" :class="{'admin-option-chip-wrap--temporarily-disabled':disabled&&disabledMessage}"><label class="admin-option-chip" :class="{'admin-option-chip--selected':selected,'admin-option-chip--disabled':disabled,'admin-option-chip--error':error}"><input class="admin-option-chip__input" type="checkbox" :checked="selected" :disabled="disabled" :aria-disabled="disabled||undefined" :aria-describedby="describedBy" :aria-invalid="error||undefined" @change="emit('toggle')"><Check v-if="selected" :size="15" aria-hidden="true"/>{{ label }}</label><AdminSelectionHint v-if="disabled&&disabledMessage" :message="disabledMessage" :label="helpLabel??`Waarom kan ${label} niet worden geselecteerd?`" variant="limit"/></span></template>
