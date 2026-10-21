<script setup lang="ts">
import { computed } from "vue";
import { rosterStaffingState } from "../../services/rosterStaffingState";
import { rosterModulesForSchool, rosterModulesForStaff } from "../../utils/rosterPresentation";
import type {
  DashboardRosterGroup,
  DashboardRosterSession,
  RosterStaffMember,
  RosterStaffingSettings,
  RosterManualTimeBlock,
} from "../../types/roster";

const props = defineProps<{
  groups: DashboardRosterGroup[];
  sessions: DashboardRosterSession[];
  rounds?: RosterManualTimeBlock[];
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

const hasViSessions = computed(() =>
  props.sessions.some((session) => session.moduleKey === "Voedsel-Innovatie"),
);

const displayStaff = computed(() => {
  return props.staffCatalog
    .filter((member) => rosterModulesForStaff(props.sessions, props.groups, member.id, props.staffingSettings.cookStaffId).length > 0)
    .sort((left, right) => {
      if (hasViSessions.value && left.id === props.staffingSettings.cookStaffId) return -1;
      if (hasViSessions.value && right.id === props.staffingSettings.cookStaffId) return 1;

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
  if (props.rounds?.length) {
    const blocks = props.rounds.flatMap<DisplaySlot>((block, index) => {
      if (block.type !== "round") return [{ ...block, kind: "neutral", key: `block-${index}` }];
      if (activities.some((slot) => slot.startTime < block.endTime && slot.endTime > block.startTime)) return [];
      return [{ ...block, kind: "activity", key: `empty-${index}`, sessions: [] }];
    });
    return [...activities, ...blocks].sort((a, b) => a.startTime.localeCompare(b.startTime));
  }
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

const staffModules = computed(() => new Map(activitySlots.value.flatMap((slot) =>
  displayStaff.value.map((staff) => [
    `${slot.key}|${staff.id}`,
    rosterModulesForStaff(slot.sessions, props.groups, staff.id, props.staffingSettings.cookStaffId),
  ] as const),
)));
const schoolModules = computed(() => new Map(activitySlots.value.map((slot) => [
  slot.key, rosterModulesForSchool(slot.sessions, props.groups),
] as const)));

function modulesForStaff(slot: ActivitySlot, staffId: number) {
  return staffModules.value.get(`${slot.key}|${staffId}`) ?? [];
}

function modulesForSchool(slot: ActivitySlot) {
  return schoolModules.value.get(slot.key) ?? [];
}

function staffCellStyle(slot: ActivitySlot, staffId: number): Record<string, string> | undefined {
  const modules = modulesForStaff(slot, staffId);
  const module = modules.length === 1 ? modules[0] : undefined;
  return module ? { backgroundColor: module.color, color: textColor(module.color) } : undefined;
}

function formatGroups(labels: string[]): string {
  return labels.map((label) => `(${label})`).join(" ");
}

function openRequired(slot: ActivitySlot): DashboardRosterSession[] {
  return slot.sessions.filter(
    (session) => session.staffAssignments.length < session.minimumGeoFortStaff
      || session.groupSupervision?.some((group) => group.kind === "open"),
  );
}

function openGroupLabels(session: DashboardRosterSession): string[] {
  const understaffed = session.staffAssignments.length < session.minimumGeoFortStaff;
  return session.groupLabels.filter((_, index) => {
    const kind = session.groupSupervision?.find((group) => group.groupId === session.groupIds[index])?.kind;
    return kind !== "school" && (understaffed || kind === "open");
  });
}

function hasOpenCook(slot: ActivitySlot): boolean {
  return slot.sessions.some((session) => session.moduleKey === "Voedsel-Innovatie" && (session.cookStaffId ?? props.staffingSettings.cookStaffId) === null);
}

const showOpenColumn = computed(() => {
  return activitySlots.value.some(
    (slot) => openRequired(slot).length > 0 || hasOpenCook(slot),
  );
});

function schoolCellStyle(slot: ActivitySlot): Record<string, string> | undefined {
  const modules = modulesForSchool(slot);
  const session = modules.length === 1 ? modules[0] : undefined;
  if (!session) return undefined;

  return {
    backgroundColor: session.color,
    color: textColor(session.color),
  };
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

    <p v-if="sessions.length && rosterStaffingState(sessions, staffingSettings).state === 'none'"
      class="admin-inline-notice admin-inline-notice--plain">Er zijn nog geen docenten ingedeeld. Gebruik Docenten indelen of vul de begeleiding in via Handmatige correcties.</p>

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
            <th scope="col">School</th>
            <th v-if="showOpenColumn" scope="col">Open</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="slot in slots" :key="slot.key">
            <th
              scope="row"
              class="admin-roster-day-table__time"
              :class="slot.kind === 'neutral' ? 'is-neutral' : 'is-activity'"
            >
              <strong>{{ slot.startTime }}</strong>
              <span>{{ slot.endTime }}</span>
            </th>

            <td
              v-if="slot.kind === 'neutral'"
              :colspan="displayStaff.length + 1 + (showOpenColumn ? 1 : 0)"
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
                  'is-free': modulesForStaff(slot, staff.id).length === 0,
                }"
                :style="staffCellStyle(slot, staff.id)"
              >
                <div
                  v-for="module in modulesForStaff(slot, staff.id)"
                  :key="module.key"
                  class="admin-roster-module"
                  :style="{ backgroundColor: module.color, color: textColor(module.color) }"
                >
                  <strong>{{ module.isCook ? 'Kok · ' : '' }}{{ module.moduleLabel }}</strong>
                  <span class="admin-roster-group-reference admin-roster-module-groups">
                    <span v-for="(label, index) in module.groupLabels" :key="module.groupIds[index]" class="admin-roster-group-token">({{ label }})</span>
                  </span>
                </div>
                <strong v-if="modulesForStaff(slot, staff.id).length === 0" class="admin-roster-free-label">Vrij</strong>
              </td>

              <td
                class="admin-roster-grid-cell admin-roster-grid-cell--school"
                :class="{ 'has-school': modulesForSchool(slot).length > 0 }"
                :style="schoolCellStyle(slot)"
              >
                <div
                  v-if="modulesForSchool(slot).length"
                  class="admin-roster-school-fallback"
                >
                  <strong>School</strong>
                  <div
                    v-for="module in modulesForSchool(slot)"
                    :key="module.key"
                    class="admin-roster-module"
                    :style="{ backgroundColor: module.color, color: textColor(module.color) }"
                  >
                    <strong>{{ module.moduleLabel }}</strong>
                    <span class="admin-roster-group-reference admin-roster-module-groups">
                      <span v-for="(label, index) in module.groupLabels" :key="module.groupIds[index]" class="admin-roster-group-token">({{ label }})</span>
                    </span>
                  </div>
                </div>
                <span v-else>—</span>
              </td>

              <td
                v-if="showOpenColumn"
                class="admin-roster-grid-cell admin-roster-grid-cell--open"
              >
                  <div
                    v-for="session in openRequired(slot)"
                    :key="`open-${session.id}`"
                    class="is-warning"
                  >
                    <strong>OPEN</strong>
                    <span>
                      {{ session.moduleLabel }} {{ formatGroups(openGroupLabels(session)) }}
                    </span>
                  </div>

                  <div v-if="hasOpenCook(slot)" class="is-warning">
                    <strong>OPEN KOK</strong>
                    <span>Voedsel Innovatie</span>
                  </div>

                  <span
                    v-if="openRequired(slot).length === 0 && !hasOpenCook(slot)"
                  >
                    —
                  </span>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
