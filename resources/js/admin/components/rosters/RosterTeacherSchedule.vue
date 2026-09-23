<script setup lang="ts">
import { computed } from "vue";
import type {
  DashboardRosterSession,
  RosterStaffMember,
  RosterStaffingSettings,
} from "../../types/roster";

const props = defineProps<{
  sessions: DashboardRosterSession[];
  staffCatalog: RosterStaffMember[];
  selectedStaffIds: number[];
  staffingSettings: RosterStaffingSettings;
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

const displayStaff = computed(() => {
  const wanted = new Set(props.selectedStaffIds);
  if (props.staffingSettings.cookStaffId !== null) {
    wanted.add(props.staffingSettings.cookStaffId);
  }

  return props.staffCatalog
    .filter((member) => member.isActive && wanted.has(member.id))
    .sort((left, right) => {
      if (left.id === props.staffingSettings.cookStaffId) return -1;
      if (right.id === props.staffingSettings.cookStaffId) return 1;

      const leftFood = props.sessions.some(
        (session) =>
          session.moduleKey === "Voedsel-Innovatie"
          && session.staffAssignments.some((staff) => staff.id === left.id),
      );
      const rightFood = props.sessions.some(
        (session) =>
          session.moduleKey === "Voedsel-Innovatie"
          && session.staffAssignments.some((staff) => staff.id === right.id),
      );

      if (leftFood !== rightFood) return leftFood ? -1 : 1;
      return left.name.localeCompare(right.name, "nl");
    });
});

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

function sessionForStaff(slot: ActivitySlot, staffId: number): DashboardRosterSession | null {
  return slot.sessions.find((session) =>
    session.staffAssignments.some((staff) => staff.id === staffId)
  ) ?? null;
}

function isCookSlot(slot: ActivitySlot, staffId: number): boolean {
  return props.staffingSettings.cookStaffId === staffId
    && slot.sessions.some((session) => session.moduleKey === "Voedsel-Innovatie");
}

function viColor(slot: ActivitySlot): string {
  return slot.sessions.find((session) => session.moduleKey === "Voedsel-Innovatie")?.color
    ?? "#BFDDB0";
}

function viGroups(slot: ActivitySlot): string {
  return [...new Set(
    slot.sessions
      .filter((session) => session.moduleKey === "Voedsel-Innovatie")
      .flatMap((session) => session.groupLabels),
  )].join(", ");
}

function schoolFallback(slot: ActivitySlot): DashboardRosterSession[] {
  return slot.sessions.filter(
    (session) =>
      session.staffAssignments.length === 0
      && session.schoolSupervisionAllowed,
  );
}

function openRequired(slot: ActivitySlot): DashboardRosterSession[] {
  return slot.sessions.filter(
    (session) => session.staffAssignments.length < session.minimumGeoFortStaff,
  );
}

function hasOpenCook(slot: ActivitySlot): boolean {
  return props.staffingSettings.staffingMode === "with_staff"
    && props.staffingSettings.cookStaffId === null
    && slot.sessions.some((session) => session.moduleKey === "Voedsel-Innovatie");
}

function textColor(background: string): string {
  const hex = background.replace("#", "").trim();
  if (!/^[0-9a-fA-F]{6}$/.test(hex)) return "#111827";
  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);
  return ((0.2126 * r + 0.7152 * g + 0.0722 * b) / 255) < 0.5
    ? "#ffffff"
    : "#111827";
}
</script>

<template>
  <section class="admin-card admin-roster-teacher-grid">
    <div class="admin-roster-day__heading">
      <div>
        <p class="admin-eyebrow">Read-only</p>
        <h2>Docentenrooster</h2>
      </div>
      <p>Volledig afgeleid uit het lesrooster. Wijzig personeel altijd via het lesrooster.</p>
    </div>

    <div
      v-if="staffingSettings.staffingMode === 'lesson_only' && displayStaff.length === 0"
      class="admin-inline-notice"
    >
      Dit rooster is als <strong>alleen lesrooster</strong> gegenereerd. Voeg personeel toe via
      Beheren &rarr; Handmatige correcties; dit read-only rooster vult dan automatisch mee.
    </div>

    <div v-if="slots.length === 0" class="admin-roster-day__empty">
      Nog geen sessies gepland.
    </div>

    <div v-else class="admin-roster-day__wrap">
      <table class="admin-roster-day-table admin-roster-grid-table admin-roster-teacher-table">
        <thead>
          <tr>
            <th scope="col">Tijd</th>
            <th v-for="staff in displayStaff" :key="staff.id" scope="col">
              <strong>{{ staff.name }}</strong>
              <small>{{ staff.employmentType === "volunteer" ? "vrijwilliger" : "betaald" }}</small>
            </th>
            <th scope="col">School / open</th>
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
              :colspan="displayStaff.length + 1"
              class="admin-roster-grid-neutral"
            >
              {{ slot.label }}
            </td>

            <template v-else>
              <td
                v-for="staff in displayStaff"
                :key="staff.id"
                class="admin-roster-grid-cell admin-roster-grid-cell--staff"
                :class="{
                  'is-free': !sessionForStaff(slot, staff.id) && !isCookSlot(slot, staff.id),
                }"
                :style="
                  isCookSlot(slot, staff.id)
                    ? { backgroundColor: viColor(slot), color: textColor(viColor(slot)) }
                    : sessionForStaff(slot, staff.id)
                      ? {
                          backgroundColor: sessionForStaff(slot, staff.id)?.color,
                          color: textColor(sessionForStaff(slot, staff.id)?.color ?? '#ffffff'),
                        }
                      : undefined
                "
              >
                <template v-if="isCookSlot(slot, staff.id)">
                  <strong>Kok · Voedsel Innovatie</strong>
                  <span>{{ viGroups(slot) }}</span>
                </template>
                <template v-else-if="sessionForStaff(slot, staff.id)">
                  <strong>{{ sessionForStaff(slot, staff.id)?.moduleLabel }}</strong>
                  <span>{{ sessionForStaff(slot, staff.id)?.groupLabels.join(", ") }}</span>
                </template>
                <strong v-else class="admin-roster-free-label">Vrij</strong>
              </td>

              <td class="admin-roster-grid-cell admin-roster-grid-cell--fallback">
                <template v-if="staffingSettings.staffingMode === 'lesson_only'">
                  <strong>Nog niet ingevuld</strong>
                  <span>Voeg personeel toe via Handmatige correcties</span>
                </template>

                <template v-else>
                <div v-for="session in schoolFallback(slot)" :key="`school-${session.id}`">
                  <strong>School</strong>
                  <span>{{ session.moduleLabel }} · {{ session.groupLabels.join(", ") }}</span>
                </div>

                <div
                  v-for="session in openRequired(slot)"
                  :key="`open-${session.id}`"
                  class="is-warning"
                >
                  <strong>OPEN</strong>
                  <span>{{ session.moduleLabel }} · {{ session.groupLabels.join(", ") }}</span>
                </div>

                <div v-if="hasOpenCook(slot)" class="is-warning">
                  <strong>OPEN KOK</strong>
                  <span>Voedsel Innovatie</span>
                </div>

                <span
                  v-if="schoolFallback(slot).length === 0 && openRequired(slot).length === 0 && !hasOpenCook(slot)"
                >
                  Rond
                </span>
                </template>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
