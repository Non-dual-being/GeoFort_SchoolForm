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
      <thead><tr><th scope="col">Bezoekdatum</th><th scope="col">School</th><th scope="col">Status</th><th scope="col">Sector</th><th scope="col">Programma</th><th scope="col">Leerlingen</th><th scope="col">Excl. btw</th><th scope="col">Btw</th><th scope="col">Incl. btw</th><th scope="col">Prijsstatus</th></tr></thead>
      <tbody><tr v-for="booking in bookings" :key="booking.id"><td class="admin-revenue-table__date" data-label="Bezoekdatum">{{ dateLabel(booking.visitDate) }}</td><td class="admin-revenue-table__school" data-label="School"><RouterLink :to="`/aanvragen/${booking.id}`">{{ booking.schoolName }}</RouterLink></td><td class="admin-revenue-table__status" data-label="Status"><span class="admin-revenue-badge">{{ booking.status }}</span></td><td data-label="Sector">{{ booking.sectorLabel }}</td><td data-label="Programma">{{ booking.program }}</td><td data-label="Leerlingen">{{ booking.studentCount }}</td><template v-if="booking.amounts"><td class="admin-revenue-table__money" data-label="Excl. btw">{{ formatEuroCents(booking.amounts.totalExclVatCents) }}</td><td class="admin-revenue-table__money" data-label="Btw">{{ formatEuroCents(booking.amounts.vatCents) }}</td><td class="admin-revenue-table__money admin-revenue-table__money--total" data-label="Incl. btw">{{ formatEuroCents(booking.amounts.totalInclVatCents) }}</td></template><template v-else><td data-label="Prijs" colspan="3" class="admin-revenue-table__missing">Prijs ontbreekt</td></template><td class="admin-revenue-table__price-status" data-label="Prijsstatus"><span class="admin-revenue-badge" :class="{'admin-revenue-badge--warning': booking.snapshotState !== 'complete'}">{{ snapshotLabels[booking.snapshotState] }}</span></td></tr></tbody>
    </table>
  </div>
</template>
