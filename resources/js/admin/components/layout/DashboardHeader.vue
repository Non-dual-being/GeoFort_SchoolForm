<script setup lang="ts">
import { RouterLink, useRoute } from "vue-router";
import { inject } from "vue";
import { Settings } from "lucide-vue-next";
import { adminBootstrapKey } from "../../types/admin";
const bootstrapData = inject(adminBootstrapKey);
const route = useRoute();

defineProps<{ logoutCsrfToken: string }>();
</script>

<template>
  <header class="admin-header">
    <RouterLink class="admin-brand-link" to="/">
      <span class="admin-brand-link__name">GeoFort Onderwijs</span>
      <span class="admin-brand-link__section">Dashboard</span>
    </RouterLink>

    <div class="admin-header__actions">
      <RouterLink v-if="bootstrapData && ['admin','planner'].includes(bootstrapData.user.role)" to="/configuratie" class="admin-settings-link" :class="{active: route.path.startsWith('/configuratie')}" aria-label="Configuratie" :aria-current="route.path.startsWith('/configuratie') ? 'page' : undefined">
        <Settings :size="23" aria-hidden="true" /><span class="admin-settings-link__tooltip" aria-hidden="true">Configuratie</span>
      </RouterLink>
    <form class="admin-logout-form" method="post" action="/auth/logout.php">
      <input type="hidden" name="_csrf" :value="logoutCsrfToken">
      <button class="admin-button admin-button--secondary admin-logout-button" type="submit">
        Uitloggen
      </button>
    </form>
    </div>
  </header>
</template>
