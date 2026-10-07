<script setup lang="ts">
import { RouterLink } from "vue-router";
import { ChartNoAxesCombined, Download, Euro } from "lucide-vue-next";
</script>

<template>
  <nav class="admin-navigation" aria-label="Dashboardnavigatie">
    <div class="admin-navigation__inner">
      <RouterLink class="admin-navigation__link" to="/">Overzicht</RouterLink>
      <RouterLink class="admin-navigation__link" to="/aanvragen">Aanvragen</RouterLink>
      <RouterLink class="admin-navigation__link" to="/agenda">Agenda</RouterLink>
      <RouterLink class="admin-navigation__link" to="/roosters">Roosters</RouterLink>
      <RouterLink class="admin-navigation__link" to="/export">
        <Download :size="17" aria-hidden="true" />
        Export
      </RouterLink>
      <RouterLink class="admin-navigation__link" to="/omzet">
        <Euro :size="17" aria-hidden="true" />
        Omzet
      </RouterLink>
      <RouterLink class="admin-navigation__link" to="/analytics">
        <ChartNoAxesCombined :size="17" aria-hidden="true" />
        Analytics
      </RouterLink>
    </div>
  </nav>
</template>
