<script setup lang="ts">
import { computed } from "vue";
import { Ban, CalendarX2 } from "lucide-vue-next";
import type { CalendarOverviewAggregate, CalendarOverviewDay, CalendarOverviewStatusFilter } from "../../types/dashboardCalendarOverview";

const props = defineProps<{ day: CalendarOverviewDay; aggregates: CalendarOverviewAggregate[]; statusFilter: CalendarOverviewStatusFilter }>();
const totalBookings = computed(() => props.aggregates.reduce((sum, item) => sum + item.bookingCount, 0));
const students = computed(() => props.aggregates.reduce((sum, item) => sum + item.studentCount, 0));
const invalid = computed(() => props.aggregates.reduce((sum, item) => sum + item.unknownStudentCount + item.invalidStudentCount, 0));
const hasAnyBookings = computed(() => props.day.aggregates.some((item) => item.bookingCount > 0));
const grouped = computed(() => ["Definitief", "In optie", "Afgewezen"].map((status) => ({
  status,
  count: props.aggregates.filter((item) => item.status === status).reduce((sum, item) => sum + item.bookingCount, 0),
})).filter((item) => item.count > 0));
const statusClass = (status: string): string => status === "Definitief" ? "admin-status--confirmed" : status === "Afgewezen" ? "admin-status--rejected" : "admin-status--option";
const fullDate = computed(() => new Intl.DateTimeFormat("nl-NL", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${props.day.date}T00:00:00Z`)));
const stateText = computed(() => {
  if (!props.day.inSelectedMonth) return "Buiten de geselecteerde maand";
  if (props.day.disabled) return props.day.hasExcludedBookingsOnBlockedDate ? "Geblokkeerd, bestaande planning" : "Geblokkeerd";
  if (!props.day.isBookableWeekday) return "Niet boekbaar";
  if (!totalBookings.value) return hasAnyBookings.value ? "Geen resultaten" : "Geen boekingen";
  if (props.statusFilter === "all") return grouped.value.map((item) => `${item.count} ${item.status}`).join(", ");
  const minimum = invalid.value ? "minimaal " : "";
  return `${totalBookings.value} ${props.statusFilter}, ${minimum}${students.value} leerlingen${invalid.value ? `, ${invalid.value} zonder geldig leerlingenaantal` : ""}`;
});
const ariaLabel = computed(() => `${fullDate.value}. ${props.day.isToday ? "Vandaag. " : ""}${props.day.isPast ? "Verleden. " : ""}${stateText.value}.`);
</script>

<template>
  <div class="admin-calendar-overview-day" role="gridcell" :class="{ 'is-outside': !day.inSelectedMonth, 'is-blocked': Boolean(day.disabled), 'is-past': day.isPast, 'is-today': day.isToday }" :aria-label="ariaLabel" :title="ariaLabel">
    <span class="admin-calendar-overview-day__number">{{ Number(day.date.slice(-2)) }}</span>
    <template v-if="day.inSelectedMonth">
      <span v-if="day.disabled" class="admin-calendar-overview-day__state"><Ban :size="14" aria-hidden="true" />Geblokkeerd<small v-if="day.hasExcludedBookingsOnBlockedDate">Bestaande planning</small></span>
      <span v-else-if="!day.isBookableWeekday" class="admin-calendar-overview-day__state"><CalendarX2 :size="14" aria-hidden="true" />Niet boekbaar</span>
      <template v-else-if="totalBookings">
        <div v-if="statusFilter === 'all'" class="admin-calendar-overview-day__badges">
          <span v-for="item in grouped" :key="item.status" class="admin-status admin-calendar-overview-badge" :class="statusClass(item.status)"><strong>{{ item.count }}</strong> {{ item.status }}</span>
        </div>
        <div v-else class="admin-calendar-overview-day__single">
          <strong>{{ totalBookings }} {{ statusFilter }}</strong>
          <span>{{ invalid ? "minimaal " : "" }}{{ students }} leerlingen</span>
          <small v-if="invalid">{{ invalid }} zonder geldig aantal</small>
        </div>
      </template>
      <span v-else class="admin-calendar-overview-day__empty">{{ hasAnyBookings ? "Geen resultaten" : "Geen boekingen" }}</span>
    </template>
  </div>
</template>
