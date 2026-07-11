<script setup lang="ts">
import { RouterLink } from "vue-router";

defineProps<{ logoutCsrfToken: string }>();
</script>

<template>
  <header class="admin-header">
    <RouterLink class="admin-brand-link" to="/">
      <span class="admin-brand-link__name">GeoFort Onderwijs</span>
      <span class="admin-brand-link__section">Dashboard</span>
    </RouterLink>

    <form class="admin-logout-form" method="post" action="/auth/logout.php">
      <input type="hidden" name="_csrf" :value="logoutCsrfToken">
      <button class="admin-button admin-button--secondary admin-logout-button" type="submit">
        Uitloggen
      </button>
    </form>
  </header>
</template>
