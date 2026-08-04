<script setup lang="ts">
import { RouterLink } from "vue-router";
import type { DashboardOverviewBooking } from "../../types/dashboardOverview";
import { formatDashboardDate } from "../../utils/dashboardOverviewPresentation";
defineProps<{ total: number; items: DashboardOverviewBooking[] }>();
</script>

<template>
  <article class="admin-card admin-overview-card admin-overview-card--options">
    <header class="admin-overview-card__header"><div><p class="admin-overview-card__count">{{ total }}</p><h2>Aanvragen in optie</h2></div></header>
    <p v-if="items.length === 0" class="admin-overview-empty">Er zijn geen aanvragen in optie.</p>
    <ul v-else class="admin-overview-bookings">
      <li v-for="item in items" :key="item.id" :class="{ 'is-expired': item.expired }">
        <div><strong>{{ item.visitDate ? formatDashboardDate(item.visitDate) : "Datum onbekend" }}</strong><span v-if="item.expired" class="admin-overview-warning">Verlopen optie</span></div>
        <div><span>{{ item.schoolName }}</span><small>{{ item.programLabel }} · {{ item.studentCount ?? "Onbekend" }} leerlingen</small></div>
      </li>
    </ul>
    <RouterLink class="admin-button admin-button--secondary admin-overview-card__cta" :to="{ name: 'bookings', query: { status: 'In optie' } }">Bekijk alle in optie <span aria-hidden="true">→</span></RouterLink>
  </article>
</template>
