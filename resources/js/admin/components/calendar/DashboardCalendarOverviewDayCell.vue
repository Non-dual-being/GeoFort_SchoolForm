<script setup lang="ts">
import { computed } from "vue";
import { Ban, CalendarX2 } from "lucide-vue-next";
import type { CalendarOverviewAggregate, CalendarOverviewDay, CalendarOverviewStatusFilter, CalendarOverviewStatusOption } from "../../types/dashboardCalendarOverview";
import { calendarOverviewBookingCountClass, calendarOverviewDayStatusClass, calendarOverviewOccupancyLabel, calendarOverviewOccupancyLevel, calendarOverviewStatusClass } from "../../utils/calendarOverviewPresentation";

const props = defineProps<{ day: CalendarOverviewDay; aggregates: CalendarOverviewAggregate[]; statusFilter: CalendarOverviewStatusFilter; statusOptions: CalendarOverviewStatusOption[]; effectiveCapacity: number }>();
defineEmits<{ details: [date: string] }>();
const totalBookings = computed(() => props.aggregates.reduce((sum, item) => sum + item.bookingCount, 0));
const students = computed(() => props.aggregates.reduce((sum, item) => sum + item.studentCount, 0));
const invalid = computed(() => props.aggregates.reduce((sum, item) => sum + item.unknownStudentCount + item.invalidStudentCount, 0));
const hasAnyBookings = computed(() => props.day.aggregates.some((item) => item.bookingCount > 0));
const isClosed = computed(() => Boolean(props.day.disabled) || !props.day.isBookableWeekday);
const grouped = computed(() => props.statusOptions.map((status) => ({
  ...status,
  count: props.aggregates.filter((item) => item.status === status.value).reduce((sum, item) => sum + item.bookingCount, 0),
  students: props.aggregates.filter((item) => item.status === status.value).reduce((sum, item) => sum + item.studentCount, 0),
  invalid: props.aggregates.filter((item) => item.status === status.value).reduce((sum, item) => sum + item.unknownStudentCount + item.invalidStudentCount, 0),
})).filter((item) => item.count > 0));
const selectedStatus = computed(() => props.statusFilter === "all" ? null : props.statusOptions.find((status) => status.value === props.statusFilter) ?? null);
const occupancyLevel = computed(() => calendarOverviewOccupancyLevel(students.value, props.effectiveCapacity));
const occupancyText = computed(() => calendarOverviewOccupancyLabel(occupancyLevel.value));
const accentEligible = computed(() => Boolean(selectedStatus.value && totalBookings.value > 0 && props.day.inSelectedMonth && !props.day.disabled && !props.day.isPast && props.day.isBookableWeekday));
const accentClasses = computed(() => accentEligible.value && selectedStatus.value ? [
  calendarOverviewDayStatusClass(selectedStatus.value.presentation),
  calendarOverviewBookingCountClass(totalBookings.value),
] : []);
const fullDate = computed(() => new Intl.DateTimeFormat("nl-NL", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${props.day.date}T00:00:00Z`)));
const stateText = computed(() => {
  if (!props.day.inSelectedMonth) return "Buiten de geselecteerde maand";
  if (!totalBookings.value) return hasAnyBookings.value ? "Geen resultaten" : "Geen boekingen";
  if (props.statusFilter === "all") return grouped.value.map((item) => `${item.count} ${item.label}, ${item.invalid ? 'minimaal ' : ''}${item.students} leerlingen`).join(", ");
  const minimum = invalid.value ? "minimaal " : "";
  const requests = `${totalBookings.value} aanvraag${totalBookings.value === 1 ? "" : "en"} met status ${selectedStatus.value?.label}`;
  return `${requests}, ${minimum}${students.value} leerlingen${invalid.value ? `, ${invalid.value} zonder geldig leerlingenaantal` : ""}, ${occupancyText.value} ten opzichte van de geconfigureerde capaciteit`;
});
const ariaLabel = computed(() => `${fullDate.value}. ${props.day.isToday ? "Vandaag. " : ""}${props.day.isPast ? "Verleden. " : ""}${isClosed.value ? "Geblokkeerd voor nieuwe aanvragen. " : ""}${stateText.value}.`);
</script>

<template>
  <div class="admin-calendar-overview-day" role="gridcell" :class="[{ 'is-outside': !day.inSelectedMonth, 'is-blocked': isClosed, 'is-past': day.isPast, 'is-today': day.isToday }, accentClasses]" :aria-label="ariaLabel" :title="ariaLabel">
    <span class="admin-calendar-overview-day__number">{{ Number(day.date.slice(-2)) }}</span>
    <template v-if="day.inSelectedMonth">
      <span v-if="day.disabled" class="admin-calendar-overview-day__state"><Ban :size="14" aria-hidden="true" /><span>Geblokkeerd</span></span>
      <span v-else-if="!day.isBookableWeekday" class="admin-calendar-overview-day__state"><CalendarX2 :size="14" aria-hidden="true" />Niet boekbaar</span>
      <template v-if="totalBookings">
        <div v-if="statusFilter === 'all'" class="admin-calendar-overview-day__badges">
          <span v-for="item in grouped" :key="item.value" class="admin-status admin-calendar-overview-badge" :class="calendarOverviewStatusClass(item.presentation)"><strong>{{ item.count }}</strong> {{ item.label }} · {{ item.invalid ? "minimaal " : "" }}{{ item.students }} leerlingen</span>
        </div>
        <div v-else class="admin-calendar-overview-day__single">
          <strong class="admin-status admin-calendar-overview-day__status-badge" :class="selectedStatus ? calendarOverviewStatusClass(selectedStatus.presentation) : ''">{{ totalBookings }} {{ selectedStatus?.label }}</strong>
          <span class="admin-calendar-overview-day__student-badge" :class="`is-occupancy-${occupancyLevel}`">{{ invalid ? "minimaal " : "" }}{{ students }} leerlingen</span>
          <small v-if="invalid">{{ invalid }} zonder geldig aantal</small>
        </div>
      </template>
      <span v-else class="admin-calendar-overview-day__empty">{{ hasAnyBookings ? "Geen resultaten" : "Geen boekingen" }}</span>
      <button v-if="hasAnyBookings" type="button" class="admin-calendar-overview-day__details" :aria-label="`Bestaande planning op ${fullDate} bekijken`" @click="$emit('details', day.date)">Bestaande planning</button>
    </template>
  </div>
</template>
