<script setup lang="ts">
export type CalendarPageMode = "overview" | "management";
defineProps<{ modelValue: CalendarPageMode }>();
defineEmits<{ "update:modelValue": [value: CalendarPageMode] }>();
</script>

<template>
  <div class="admin-calendar-page-mode" aria-label="Agendaweergave">
    <button type="button" :aria-pressed="modelValue === 'overview'" @click="$emit('update:modelValue', 'overview')">Overzicht</button>
    <button type="button" :aria-pressed="modelValue === 'management'" @click="$emit('update:modelValue', 'management')">Beheer</button>
  </div>
</template>
