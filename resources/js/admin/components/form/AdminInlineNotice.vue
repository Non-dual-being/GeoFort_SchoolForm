<script setup lang="ts">
import {CircleAlert,CircleCheck,Info,TriangleAlert} from "lucide-vue-next";
const props=withDefaults(defineProps<{variant?:"info"|"warning"|"error"|"success";title?:string}>(),{variant:"info",title:undefined});
const icons={info:Info,warning:TriangleAlert,error:CircleAlert,success:CircleCheck};
</script>
<template><div class="admin-inline-notice" :class="`admin-inline-notice--${variant}`" :role="variant==='error'?'alert':'status'"><component :is="icons[props.variant]" :size="21" aria-hidden="true"/><div><strong v-if="title">{{ title }}</strong><div class="admin-inline-notice__content"><slot/></div></div></div></template>
