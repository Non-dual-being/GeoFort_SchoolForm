<script setup lang="ts">
import { inject, onBeforeUnmount, onMounted, ref } from "vue";
import AdminIdentityCard from "../components/dashboard/AdminIdentityCard.vue";
import DashboardOptionListCard from "../components/dashboard/DashboardOptionListCard.vue";
import DashboardNextOptionCard from "../components/dashboard/DashboardNextOptionCard.vue";
import DashboardMonthCard from "../components/dashboard/DashboardMonthCard.vue";
import AdminButton from "../components/form/AdminButton.vue";
import { fetchDashboardOverview } from "../services/dashboardOverviewApi";
import type { DashboardOverviewData } from "../types/dashboardOverview";
import { adminBootstrapKey } from "../types/admin";

const bootstrapData = inject(adminBootstrapKey);
if (!bootstrapData) throw new Error("Admin bootstrapcontext ontbreekt.");
const dashboard = ref<DashboardOverviewData | null>(null);
const loading = ref(true);
const error = ref(false);
const controller = new AbortController();
async function load(): Promise<void> { loading.value = true; error.value = false; try { dashboard.value = await fetchDashboardOverview(controller.signal); } catch (caught) { if (!(caught instanceof DOMException && caught.name === "AbortError")) error.value = true; } finally { loading.value = false; } }
onMounted(() => void load());
onBeforeUnmount(() => controller.abort());
</script>

<template>
  <section class="admin-overview-page" aria-labelledby="dashboard-title">
    <div class="admin-dashboard-intro">
      <p class="admin-eyebrow">Onderwijsformulier 2.0</p>
      <h1 id="dashboard-title">{{ bootstrapData.dashboardTitle }}</h1>
    </div>
    <AdminIdentityCard :user="bootstrapData.user" />
    <div v-if="error" class="admin-card admin-overview-state" role="alert"><p>De actuele dashboardgegevens konden niet worden geladen.</p><AdminButton @click="load">Opnieuw proberen</AdminButton></div>
    <p v-else-if="loading" class="admin-card admin-overview-state" role="status">Dashboardgegevens laden…</p>
    <div v-else-if="dashboard" class="admin-overview-grid">
      <DashboardOptionListCard :total="dashboard.options.total" :items="dashboard.options.items" />
      <DashboardNextOptionCard :visit-date="dashboard.nextOption.visitDate" :items="dashboard.nextOption.items" :today="dashboard.generatedForDate" />
      <DashboardMonthCard :statistics="dashboard.currentMonth" />
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
