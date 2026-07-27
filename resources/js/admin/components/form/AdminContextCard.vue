<script setup lang="ts">
import type {Component} from "vue";
defineProps<{label:string;value:string;subtitle?:string;icon?:Component}>();
</script>
<template><article class="admin-context-card"><component :is="icon" v-if="icon" class="admin-context-card__icon" :size="20" aria-hidden="true"/><div><p class="admin-context-card__label">{{ label }}</p><p class="admin-context-card__value">{{ value }}</p><p v-if="subtitle" class="admin-context-card__subtitle">{{ subtitle }}</p></div></article></template>
