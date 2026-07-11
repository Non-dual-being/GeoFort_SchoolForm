<script setup lang="ts">
import { provide } from "vue";
import { RouterView } from "vue-router";
import DashboardHeader from "./components/layout/DashboardHeader.vue";
import DashboardNavigation from "./components/layout/DashboardNavigation.vue";
import DashboardFooter from "./components/layout/DashboardFooter.vue";
import AdminFlash from "./components/feedback/AdminFlash.vue";
import { useAdminFlash } from "./composables/useAdminFlash";
import { adminBootstrapKey, type AdminBootstrapData } from "./types/admin";

const props = defineProps<{ bootstrapData: AdminBootstrapData }>();
provide(adminBootstrapKey, props.bootstrapData);

const { messages, remove } = useAdminFlash(props.bootstrapData.flashMessages);
</script>

<template>
  <div class="admin-app-shell">
    <DashboardHeader :logout-csrf-token="bootstrapData.logoutCsrfToken" />
    <DashboardNavigation />

    <main class="admin-main">
      <div class="admin-shell">
        <div class="admin-flash-stack" aria-label="Meldingen">
          <AdminFlash
            v-for="message in messages"
            :key="message.id"
            :message="message"
            @removed="remove(message.id)"
          />
        </div>
        <RouterView />
      </div>
    </main>

    <DashboardFooter :public-booking-url="bootstrapData.publicBookingUrl" />
  </div>
</template>
