<script setup lang="ts">
import { computed } from "vue";

import type { DashboardRosterSession } from "../../types/roster";

const props = defineProps<{
  sessions: DashboardRosterSession[];
}>();

interface StaffingSlot {
  key: string;
  startTime: string;
  endTime: string;
  sessions: DashboardRosterSession[];
  geoFortStaff: number;
  schoolEligible: number;
}

const slots = computed<StaffingSlot[]>(() => {
  const map = new Map<string, StaffingSlot>();

  for (const session of props.sessions) {
    const key = `${session.startTime}-${session.endTime}`;
    const current = map.get(key) ?? {
      key,
      startTime: session.startTime,
      endTime: session.endTime,
      sessions: [],
      geoFortStaff: 0,
      schoolEligible: 0,
    };

    current.sessions.push(session);
    current.geoFortStaff += session.minimumGeoFortStaff;

    if (session.schoolSupervisionAllowed) {
      current.schoolEligible += 1;
    }

    map.set(key, current);
  }

  return [...map.values()].sort((left, right) =>
    left.startTime.localeCompare(right.startTime)
  );
});

const peakGeoFortStaff = computed(() =>
  slots.value.reduce(
    (maximum, slot) => Math.max(maximum, slot.geoFortStaff),
    0,
  )
);

const totalSchoolEligible = computed(() =>
  slots.value.reduce(
    (total, slot) => total + slot.schoolEligible,
    0,
  )
);
</script>

<template>
  <section class="admin-card admin-roster-staffing">
    <div class="admin-roster-staffing__heading">
      <div>
        <p class="admin-eyebrow">Personeelslaag</p>
        <h2>Personeelsbehoefte per tijdvak</h2>
      </div>

      <div class="admin-roster-staffing__summary">
        <div>
          <span>Piek GeoFort</span>
          <strong>{{ peakGeoFortStaff }}</strong>
        </div>
        <div>
          <span>Schoolbegeleiding mogelijk</span>
          <strong>{{ totalSchoolEligible }}</strong>
        </div>
      </div>
    </div>

    <p>
      Dit is nog geen medewerkerstoewijzing. De telling volgt de huidige
      <strong>sessies</strong>: een sessie die GeoFort-begeleiding vereist telt
      minimaal Ã©Ã©n GeoFort-medewerker, ongeacht hoeveel groepen bewust aan diezelfde
      sessie gekoppeld zijn.
    </p>

    <div v-if="slots.length === 0" class="admin-roster-day__empty">
      Personeelsbehoefte verschijnt zodra er sessies gepland zijn.
    </div>

    <div v-else class="admin-roster-staffing__slots">
      <article
        v-for="slot in slots"
        :key="slot.key"
        class="admin-roster-staffing-slot"
      >
        <header class="admin-roster-staffing-slot__header">
          <div>
            <strong>{{ slot.startTime }} - {{ slot.endTime }}</strong>
            <span>{{ slot.sessions.length }} sessies</span>
          </div>

          <div class="admin-roster-staffing-slot__numbers">
            <span>
              GeoFort minimaal
              <strong>{{ slot.geoFortStaff }}</strong>
            </span>
            <span>
              School mogelijk
              <strong>{{ slot.schoolEligible }}</strong>
            </span>
          </div>
        </header>

        <div class="admin-roster-staffing-slot__activities">
          <div
            v-for="session in slot.sessions"
            :key="session.id"
            class="admin-roster-staffing-activity"
            :style="{ '--roster-module-color': session.color }"
          >
            <span
              class="admin-roster-module-dot"
              aria-hidden="true"
            />

            <div>
              <strong>{{ session.moduleLabel }}</strong>
              <span>
                {{ session.groupLabels.join(", ") }}
                <template v-if="session.location">
                  &middot; {{ session.location }}
                </template>
              </span>
            </div>

            <span
              v-if="session.schoolSupervisionAllowed"
              class="admin-roster-staffing-activity__badge"
            >
              Schoolbegeleiding mogelijk
            </span>

            <span
              v-else
              class="admin-roster-staffing-activity__badge"
            >
              GeoFort: {{ session.minimumGeoFortStaff }}
            </span>
          </div>
        </div>
      </article>
    </div>

    <div class="admin-inline-notice admin-roster-staffing__next">
      Volgende personeelsstap: echte medewerkers aan sessies koppelen, beschikbaarheid
      controleren en tussenuren minimaliseren. De A/B/C-codes uit Excel zijn daarvoor
      niet nodig.
    </div>
  </section>
</template>