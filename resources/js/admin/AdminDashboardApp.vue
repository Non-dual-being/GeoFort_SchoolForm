<script setup lang="ts">
import { provide } from "vue";
import { useAdminSession } from "./composables/useAdminSession";
import { RouterView } from "vue-router";
import DashboardHeader from "./components/layout/DashboardHeader.vue";
import DashboardNavigation from "./components/layout/DashboardNavigation.vue";
import DashboardFooter from "./components/layout/DashboardFooter.vue";
import AdminFlash from "./components/feedback/AdminFlash.vue";
import { useAdminFlash } from "./composables/useAdminFlash";
import { adminBootstrapKey, type AdminBootstrapData } from "./types/admin";

const props = defineProps<{ bootstrapData: AdminBootstrapData }>();
provide(adminBootstrapKey, props.bootstrapData);
const { expired, warning, staySignedIn } = useAdminSession(props.bootstrapData);

const { messages, remove } = useAdminFlash(props.bootstrapData.flashMessages);
</script>

<template>
  <div v-if="!expired" class="admin-app-shell">
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
        <div v-if="warning" class="admin-inline-notice" role="status">
          Je wordt binnenkort uitgelogd wegens inactiviteit. Bewaar je wijzigingen of kies
          <button type="button" class="admin-button admin-button--secondary" @click="staySignedIn">Ingelogd blijven</button>.
        </div>
        <RouterView />
      </div>
    </main>

    <DashboardFooter :public-booking-url="bootstrapData.publicBookingUrl" />
  </div>
  <p v-else role="alert" class="admin-shell">Je sessie is verlopen. Je wordt doorgestuurd naar het inlogscherm.</p>
</template>
