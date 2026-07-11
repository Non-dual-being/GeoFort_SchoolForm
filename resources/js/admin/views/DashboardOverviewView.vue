<script setup lang="ts">
import { inject } from "vue";
import AdminIdentityCard from "../components/dashboard/AdminIdentityCard.vue";
import FutureFeaturesCard from "../components/dashboard/FutureFeaturesCard.vue";
import { adminBootstrapKey } from "../types/admin";

const bootstrapData = inject(adminBootstrapKey);
if (!bootstrapData) throw new Error("Admin bootstrapcontext ontbreekt.");
</script>

<template>
  <section aria-labelledby="dashboard-title">
    <div class="admin-dashboard-intro">
      <p class="admin-eyebrow">Onderwijsformulier 2.0</p>
      <h1 id="dashboard-title">{{ bootstrapData.dashboardTitle }}</h1>
    </div>
    <div class="admin-grid">
      <AdminIdentityCard :user="bootstrapData.user" />
      <FutureFeaturesCard />
    </div>
    <a
      class="admin-button admin-button--primary admin-button--external admin-public-link"
      :href="bootstrapData.publicBookingUrl"
      target="_blank"
      rel="noopener noreferrer"
    >
      Open publieke reserveringspagina
      <span aria-hidden="true">↗</span>
      <span class="sr-only">(opent in een nieuw tabblad)</span>
    </a>
  </section>
</template>
