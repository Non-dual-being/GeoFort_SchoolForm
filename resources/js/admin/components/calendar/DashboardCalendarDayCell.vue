<script setup lang="ts">
import { TriangleAlert } from "lucide-vue-next";
import type { DashboardCalendarDay } from "../../types/dashboardCalendar";

defineProps<{
  day: DashboardCalendarDay;
  outsideMonth: boolean;
  selected: boolean;
  today: boolean;
}>();

defineEmits<{ select: [day: DashboardCalendarDay] }>();
</script>

<template>
  <button
    type="button"
    class="admin-calendar-day"
    :class="[
      `is-${day.state}`,
      {
        'is-outside': outsideMonth,
        'is-selected': selected,
        'is-today': today,
      },
    ]"
    :aria-pressed="selected"
    :aria-label="`${day.date}: ${day.bookingCount} actieve boekingen, ${day.studentCount} leerlingen`"
    @click="$emit('select', day)"
  >
    <span class="admin-calendar-day__number">{{ Number(day.date.slice(-2)) }}</span>
    <span v-if="day.manuallyBlocked" class="admin-calendar-day__block">Geblokkeerd</span>
    <span v-else class="admin-calendar-day__capacity">
      {{ day.remainingCapacity ?? "—" }} vrij
    </span>
    <span v-if="day.bookingCount" class="admin-calendar-day__bookings">
      {{ day.bookingCount }} boeking{{ day.bookingCount === 1 ? "" : "en" }}
    </span>
    <span class="admin-calendar-day__status">
      <span v-if="day.optionBookingCount">{{ day.optionBookingCount }} optie</span>
      <span v-if="day.confirmedBookingCount">{{ day.confirmedBookingCount }} definitief</span>
    </span>
    <TriangleAlert
      v-if="day.warnings.length"
      class="admin-calendar-day__warning"
      :size="15"
      aria-hidden="true"
    />
  </button>
</template>
