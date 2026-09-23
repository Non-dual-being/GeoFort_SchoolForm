<script setup lang="ts">
import { computed } from "vue";
import type { DashboardRosterGroup, DashboardRosterSession } from "../../types/roster";

const props = defineProps<{
  groups: DashboardRosterGroup[];
  sessions: DashboardRosterSession[];
}>();

interface ActivitySlot {
  kind: "activity";
  key: string;
  startTime: string;
  endTime: string;
  sessions: DashboardRosterSession[];
}
interface NeutralSlot {
  kind: "neutral";
  key: string;
  startTime: string;
  endTime: string;
  label: string;
}
type DisplaySlot = ActivitySlot | NeutralSlot;

const activitySlots = computed<ActivitySlot[]>(() => {
  const map = new Map<string, ActivitySlot>();

  for (const session of props.sessions) {
    const key = `${session.startTime}-${session.endTime}`;
    const slot = map.get(key) ?? {
      kind: "activity" as const,
      key,
      startTime: session.startTime,
      endTime: session.endTime,
      sessions: [],
    };
    slot.sessions.push(session);
    map.set(key, slot);
  }

  return [...map.values()].sort((a, b) => a.startTime.localeCompare(b.startTime));
});

function minutes(value: string): number {
  const [hour, minute] = value.split(":").map(Number);
  return ((hour ?? 0) * 60) + (minute ?? 0);
}

const slots = computed<DisplaySlot[]>(() => {
  const activities = activitySlots.value;
  if (activities.length === 0) return [];

  const rows: DisplaySlot[] = [];
  const first = activities[0];

  if (first && minutes(first.startTime) > minutes("10:00")) {
    rows.push({
      kind: "neutral",
      key: "arrival",
      startTime: "10:00",
      endTime: first.startTime,
      label: "Aankomst & welkom",
    });
  }

  activities.forEach((activity, index) => {
    rows.push(activity);
    const next = activities[index + 1];
    if (!next) return;

    const gap = minutes(next.startTime) - minutes(activity.endTime);
    if (gap <= 0) return;

    rows.push({
      kind: "neutral",
      key: `gap-${activity.endTime}-${next.startTime}`,
      startTime: activity.endTime,
      endTime: next.startTime,
      label:
        minutes(activity.endTime) >= minutes("12:15") && gap >= 25
          ? "Lunch"
          : "Pauze",
    });
  });

  const last = activities.at(-1);
  const programEnd = last && minutes(last.endTime) <= minutes("12:15")
    ? "12:15"
    : "15:00";

  if (last && minutes(last.endTime) < minutes(programEnd)) {
    rows.push({
      kind: "neutral",
      key: "departure",
      startTime: last.endTime,
      endTime: programEnd,
      label: "Afscheid & vertrek",
    });
  }

  return rows;
});

function sessionFor(slot: ActivitySlot, groupId: number): DashboardRosterSession | null {
  return slot.sessions.find((session) => session.groupIds.includes(groupId)) ?? null;
}

function textColor(background: string): string {
  const hex = background.replace("#", "").trim();
  if (!/^[0-9a-fA-F]{6}$/.test(hex)) return "#111827";

  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);
  const luminance = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
  return luminance < 0.5 ? "#ffffff" : "#111827";
}
</script>

<template>
  <section class="admin-card admin-roster-day">
    <div class="admin-roster-day__heading">
      <div>
        <p class="admin-eyebrow">Lesrooster</p>
        <h2>Dagrooster</h2>
      </div>
      <p>Modulekleuren vullen de hele cel; pauze en dagonderdelen blijven neutraal.</p>
    </div>

    <div v-if="slots.length === 0" class="admin-roster-day__empty">
      Nog geen sessies gepland.
    </div>

    <div v-else class="admin-roster-day__wrap">
      <table class="admin-roster-day-table admin-roster-grid-table">
        <thead>
          <tr>
            <th scope="col">Tijd</th>
            <th v-for="group in groups" :key="group.id" scope="col">
              <strong>{{ group.label }}</strong>
              <small v-if="group.studentCount !== null">{{ group.studentCount }} leerlingen</small>
            </th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="slot in slots" :key="slot.key">
            <th scope="row" class="admin-roster-day-table__time">
              <strong>{{ slot.startTime }}</strong>
              <span>{{ slot.endTime }}</span>
            </th>

            <td
              v-if="slot.kind === 'neutral'"
              :colspan="groups.length"
              class="admin-roster-grid-neutral"
            >
              {{ slot.label }}
            </td>

            <template v-else>
              <td
                v-for="group in groups"
                :key="group.id"
                class="admin-roster-grid-cell"
                :class="{ 'is-open': !sessionFor(slot, group.id) }"
                :style="
                  sessionFor(slot, group.id)
                    ? {
                        backgroundColor: sessionFor(slot, group.id)?.color,
                        color: textColor(sessionFor(slot, group.id)?.color ?? '#ffffff'),
                      }
                    : undefined
                "
              >
                <template v-if="sessionFor(slot, group.id)">
                  <strong>{{ sessionFor(slot, group.id)?.moduleLabel }}</strong>
                  <span>{{ sessionFor(slot, group.id)?.location ?? "Locatie open" }}</span>
                </template>
                <span v-else>Open</span>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
