<script setup lang="ts">
import AdminButton from "../form/AdminButton.vue";

withDefaults(defineProps<{ title: string; actionLabel: string; disabled?: boolean }>(), {
  disabled: false,
});
defineEmits<{ action: [] }>();
</script>

<template>
  <article class="admin-calendar-action-card">
    <div class="admin-calendar-action-card__body">
      <p class="admin-eyebrow">Actie</p>
      <h3>{{ title }}</h3>
      <div class="admin-calendar-action-card__content"><slot /></div>
    </div>
    <AdminButton :disabled="disabled" @click="$emit('action')">{{ actionLabel }}</AdminButton>
  </article>
</template>
