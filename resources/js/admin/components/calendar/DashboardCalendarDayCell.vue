<script setup lang="ts">
import { TriangleAlert } from "lucide-vue-next";
import type { DashboardCalendarDay } from "../../types/dashboardCalendar";
import { disabledDateLabel } from "../../../shared/disabledDatePresentation";

defineProps<{
  day: DashboardCalendarDay;
  outsideMonth: boolean;
  selected: boolean;
  today: boolean;
  managementEligible?: boolean;
  rangeStart?: boolean;
  rangeEnd?: boolean;
  inRange?: boolean;
}>();

defineEmits<{ select: [day: DashboardCalendarDay] }>();

function isWeekend(day: DashboardCalendarDay): boolean {
  return day.disabledType === "weekend" || day.weekday >= 6;
}

function visualKind(day: DashboardCalendarDay): string {
  if (day.isPast) return "past";
  if (day.disabledType === "manual") return "manual";
  if (day.disabledType === "school_vacation") return "school-vacation";
  if (isWeekend(day)) return "weekend";
  if (day.bookingCount > 0) return "active-booking";
  return day.state;
}

function statusLabel(day: DashboardCalendarDay): string {
  if (day.isPast) return "Verleden";
  if (day.disabledType === "manual") return disabledDateLabel("manual");
  if (day.disabledType === "school_vacation") return disabledDateLabel("school_vacation");
  if (isWeekend(day)) return disabledDateLabel("weekend");
  if (day.state === "full") return "Volgeboekt";
  if (day.state === "limited") return "Beperkt beschikbaar";
  return "Beschikbaar";
}

function tooltipText(day: DashboardCalendarDay): string | null {
  if (isWeekend(day)) return "Niet beschikbaar voor onderwijsbezoeken.";
  if (day.disabledType === "school_vacation") return day.disabledReason ?? "Niet beschikbaar voor onderwijsbezoeken.";
  if (day.disabledType === "manual") return day.manualBlockReason;
  if (day.isPast) return "Deze datum is verstreken.";
  return day.remainingCapacity === null ? null : `${day.remainingCapacity} plaatsen resterend`;
}

function cellLabel(day: DashboardCalendarDay): string {
  if (day.disabledType === "manual") return disabledDateLabel("manual");
  if (day.disabledType === "school_vacation") return disabledDateLabel("school_vacation");
  return statusLabel(day);
}

function cellSupportingText(day: DashboardCalendarDay): string | null {
  if (day.disabledType === "school_vacation") return day.disabledReason;
  if (isWeekend(day) || day.disabledType === "manual" || day.isPast) return null;
  return day.remainingCapacity === null ? null : `${day.remainingCapacity} plaatsen resterend`;
}
</script>

<template>
  <button
    type="button"
    class="admin-calendar-day"
    :class="[
      `is-${day.state}`,
      `is-${visualKind(day)}`,
      {
        'is-outside': outsideMonth,
        'is-selected': selected,
        'is-today': today,
        'is-management-ineligible': managementEligible === false,
        'is-range-start': rangeStart,
        'is-range-end': rangeEnd,
        'is-in-range': inRange,
      },
    ]"
    :aria-pressed="selected"
    :aria-disabled="managementEligible === false"
    :aria-label="`${day.date}: ${statusLabel(day)}. ${day.bookingCount} actieve boekingen, ${day.studentCount} leerlingen${managementEligible === false ? '. Niet relevant en niet selecteerbaar in deze weergave' : ''}`"
    :title="`${statusLabel(day)}${tooltipText(day) ? ` — ${tooltipText(day)}` : ''}`"
    @click="$emit('select', day)"
  >
    <span class="admin-calendar-day__number">{{ Number(day.date.slice(-2)) }}</span>
    <span class="admin-calendar-day__block">{{ cellLabel(day) }}</span>
    <span
      v-if="cellSupportingText(day)"
      class="admin-calendar-day__capacity"
      :class="{ 'admin-calendar-day__reason': day.disabledType === 'school_vacation' }"
    >
      {{ cellSupportingText(day) }}
    </span>
    <span v-if="day.bookingCount" class="admin-calendar-day__bookings">
      {{ day.bookingCount }} boeking{{ day.bookingCount === 1 ? "" : "en" }} · {{ day.studentCount }} leerlingen
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
