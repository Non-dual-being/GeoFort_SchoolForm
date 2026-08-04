<script setup lang="ts">
import { RouterLink } from "vue-router";
import type { DashboardOverviewBooking } from "../../types/dashboardOverview";
import { formatDashboardDate, relativeDashboardDate } from "../../utils/dashboardOverviewPresentation";
defineProps<{ visitDate: string | null; items: DashboardOverviewBooking[]; today: string }>();
</script>

<template>
  <article class="admin-card admin-overview-card">
    <h2>Eerstvolgende optie</h2>
    <div v-if="visitDate" class="admin-overview-next-date"><strong>{{ formatDashboardDate(visitDate, true) }}</strong><span>{{ relativeDashboardDate(visitDate, today) }}</span></div>
    <p v-if="items.length > 1" class="admin-overview-note">{{ items.length }} aanvragen op deze datum</p>
    <div v-for="item in items" :key="item.id" class="admin-overview-next-item">
      <h3>{{ item.schoolName }}</h3>
      <p>{{ item.programLabel }} · {{ item.studentCount ?? "Onbekend" }} leerlingen</p>
      <p>{{ item.sectorLabel }}</p>
      <RouterLink class="admin-button admin-button--secondary" :to="{ name: 'booking-detail', params: { id: item.id } }">Open aanvraag <span aria-hidden="true">→</span></RouterLink>
    </div>
    <p v-if="items.length === 0" class="admin-overview-empty">Er zijn geen komende aanvragen in optie.</p>
  </article>
</template>
