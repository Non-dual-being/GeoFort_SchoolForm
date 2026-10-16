<script setup lang="ts">
import { ChartColumn, List } from 'lucide-vue-next';
defineProps<{ modelValue: 'chart' | 'table'; label: string }>();
defineEmits<{ 'update:modelValue': [value: 'chart' | 'table'] }>();
</script>
<template>
  <div class="staff-view-toggle" role="group" :aria-label="label">
    <button type="button" :aria-pressed="modelValue === 'chart'" @click="$emit('update:modelValue','chart')"><ChartColumn :size="16" aria-hidden="true" />Grafiek</button>
    <button type="button" :aria-pressed="modelValue === 'table'" @click="$emit('update:modelValue','table')"><List :size="16" aria-hidden="true" />Lijst</button>
  </div>
</template>
