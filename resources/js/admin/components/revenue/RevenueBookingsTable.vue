<script setup lang="ts">
import type { PriceSnapshotState, RevenueBooking } from "../../types/bookingRevenue";
import { formatEuroCents } from "../../utils/money";

defineProps<{ bookings: RevenueBooking[] }>();
const snapshotLabels: Record<PriceSnapshotState, string> = { complete: "Compleet", historical_price_unavailable: "Historische prijs ontbreekt", invalid_input: "Niet berekenbaar", missing: "Prijs ontbreekt" };
function dateLabel(value: string): string { const [y,m,d]=value.split("-"); return y&&m&&d ? `${d}-${m}-${y}` : value; }
</script>

<template>
  <div class="admin-revenue-table-wrap">
    <table class="admin-revenue-table">
      <caption class="sr-only">Boekingen binnen de geselecteerde bezoekperiode</caption>
      <thead><tr><th scope="col">Boeking</th><th scope="col">Bezoekdatum</th><th scope="col">School</th><th scope="col">Sector</th><th scope="col">Leerlingen</th><th scope="col">Status</th><th scope="col">Prijsstatus</th><th scope="col">Bezoek incl.</th><th scope="col">Catering incl.</th><th scope="col">Totaal incl.</th></tr></thead>
      <tbody><tr v-for="booking in bookings" :key="booking.id"><td><RouterLink :to="`/aanvragen/${booking.id}`">#{{ booking.id }}</RouterLink></td><td>{{ dateLabel(booking.visitDate) }}</td><td>{{ booking.schoolName }}</td><td>{{ booking.sectorLabel }}</td><td>{{ booking.studentCount }}</td><td><span class="admin-revenue-badge">{{ booking.status }}</span></td><td><span class="admin-revenue-badge" :class="{'admin-revenue-badge--warning': booking.snapshotState !== 'complete'}">{{ snapshotLabels[booking.snapshotState] }}</span></td><template v-if="booking.amounts"><td>{{ formatEuroCents(booking.amounts.visitInclVatCents) }}</td><td>{{ formatEuroCents(booking.amounts.cateringInclVatCents) }}</td><td>{{ formatEuroCents(booking.amounts.totalInclVatCents) }}</td></template><template v-else><td colspan="3" class="admin-revenue-table__missing">{{ snapshotLabels[booking.snapshotState] }}</td></template></tr></tbody>
    </table>
  </div>
</template>
