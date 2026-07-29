<script setup lang="ts">
import type { CalendarManagementMode } from "../../types/calendarDateManagement";

defineProps<{ modelValue: CalendarManagementMode }>();
const emit = defineEmits<{ "update:modelValue": [value: CalendarManagementMode] }>();

const options: Array<{ value: CalendarManagementMode; label: string }> = [
  { value: "active-bookings", label: "Actieve boekingen" },
  { value: "available-management", label: "Beschikbare datums" },
  { value: "manually-blocked", label: "Geblokkeerde datums" },
];
</script>

<template>
  <fieldset class="admin-calendar-view-selector">
    <legend class="admin-visually-hidden">Kies een agendaweergave</legend>
    <label
      v-for="option in options"
      :key="option.value"
      class="admin-calendar-view-selector__option"
      :class="{ 'is-active': modelValue === option.value }"
    >
      <input type="radio" name="calendar-view" :value="option.value" :checked="modelValue === option.value" @change="emit('update:modelValue', option.value)">
      <span>{{ option.label }}</span>
    </label>
  </fieldset>
</template>
