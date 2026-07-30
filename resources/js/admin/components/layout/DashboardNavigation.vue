<script setup lang="ts">
import { RouterLink } from "vue-router";
import { Download } from "lucide-vue-next";
</script>

<template>
  <nav class="admin-navigation" aria-label="Dashboardnavigatie">
    <div class="admin-navigation__inner">
      <RouterLink class="admin-navigation__link" to="/">Overzicht</RouterLink>
      <RouterLink class="admin-navigation__link" to="/aanvragen">Aanvragen</RouterLink>
      <RouterLink class="admin-navigation__link" to="/agenda">Agenda</RouterLink>
      <RouterLink class="admin-navigation__link" to="/export">
        <Download :size="17" aria-hidden="true" />
        Export
      </RouterLink>
    </div>
  </nav>
</template>
