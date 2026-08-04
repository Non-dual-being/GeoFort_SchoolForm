<script setup lang="ts">
import { computed } from "vue";
import type { AdminUser } from "../../types/admin";

const props = defineProps<{ user: AdminUser }>();

const previousLogin = computed(() => {
  const value = props.user.previousLoginAt;
  if (!value) return "Niet beschikbaar";

  const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(value);
  if (!match) return value;

  const [, year, month, day, hours, minutes] = match;
  return `${day}-${month}-${year} om ${hours}:${minutes}`;
});
</script>

<template>
  <aside class="admin-card admin-identity-bar" aria-label="Accountgegevens">
    <h2>{{ user.name }}</h2>
    <dl class="admin-details">
      <dt>E-mailadres</dt><dd>{{ user.email }}</dd>
      <dt>Rol</dt><dd>{{ user.role }}</dd>
      <dt>Vorige login</dt><dd>{{ previousLogin }}</dd>
    </dl>
  </aside>
</template>
