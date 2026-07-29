<script setup lang="ts">
import { RouterLink } from "vue-router";
import type { DashboardCalendarDay } from "../../types/dashboardCalendar";

defineProps<{ day: DashboardCalendarDay }>();

function formatDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number);
  return new Intl.DateTimeFormat("nl-NL", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year!, month! - 1, day!)));
}
</script>

<template>
  <aside class="admin-card admin-calendar-detail" aria-live="polite">
    <p class="admin-eyebrow">Dagoverzicht</p>
    <h2>{{ formatDate(day.date) }}</h2>

    <p v-if="day.disabledType === 'manual'" class="admin-calendar-detail__blocked">
      Handmatig geblokkeerd<span v-if="day.manualBlockReason">: {{ day.manualBlockReason }}</span>
    </p>
    <p v-else-if="day.disabledType === 'weekend' || day.weekday >= 6">
      <strong>Weekend</strong> — Niet beschikbaar voor onderwijsbezoeken.
    </p>
    <p v-else-if="day.disabledType === 'school_vacation'">
      <strong>Schoolvakantie</strong><span v-if="day.disabledReason"> — {{ day.disabledReason }}</span>
    </p>
    <p v-else-if="day.disabledReason">{{ day.disabledReason }} ({{ day.disabledType }})</p>
    <p v-else-if="day.canBlockManually">Datum beschikbaar voor beheer</p>
    <p v-else-if="!day.isPast && day.state === 'blocked'">Deze datum is door een andere regel niet beschikbaar. Alleen handmatige blokkades kunnen hier worden vrijgegeven.</p>

    <dl class="admin-calendar-detail__stats">
      <dt>Actieve boekingen</dt><dd>{{ day.bookingCount }}</dd>
      <dt>In optie</dt><dd>{{ day.optionBookingCount }}</dd>
      <dt>Definitief</dt><dd>{{ day.confirmedBookingCount }}</dd>
      <dt>Actieve leerlingen</dt><dd>{{ day.studentCount }}</dd>
      <dt>Definitieve leerlingen</dt><dd>{{ day.confirmedStudentCount }}</dd>
      <dt>Dagcapaciteit</dt><dd>{{ day.maximumCapacity ?? "Onbekend" }}</dd>
      <dt>Resterend</dt><dd>{{ day.remainingCapacity ?? "Onbekend" }}</dd>
      <dt>Programma’s</dt><dd>{{ day.programs.join(", ") || "Geen" }}</dd>
    </dl>

    <section v-if="day.warnings.length" class="admin-calendar-detail__warnings">
      <h3>Waarschuwingen</h3>
      <ul>
        <li v-for="warning in day.warnings" :key="warning.code">
          <strong>{{ warning.title }}</strong>
          <span>{{ warning.description }}</span>
        </li>
      </ul>
    </section>

    <section class="admin-calendar-detail__bookings">
      <h3>Boekingen</h3>
      <p v-if="!day.bookings.length">Geen boekingen op deze datum.</p>
      <ul v-else>
        <li v-for="booking in day.bookings" :key="booking.id">
          <div>
            <strong>{{ booking.schoolName }}</strong>
            <span>{{ booking.status }} · {{ booking.programLabel }} · {{ booking.studentCount ?? "?" }} leerlingen</span>
          </div>
          <RouterLink :to="{ name: 'booking-detail', params: { id: booking.id } }">
            Aanvraag #{{ booking.id }}
          </RouterLink>
        </li>
      </ul>
    </section>
  </aside>
</template>
