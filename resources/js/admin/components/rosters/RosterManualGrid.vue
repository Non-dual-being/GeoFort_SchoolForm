<script setup lang="ts">
import { computed, inject, ref, watch } from "vue";
import { Pencil } from "lucide-vue-next";

import AdminButton from "../form/AdminButton.vue";
import {
  deleteRosterSession,
  saveRosterSession,
} from "../../services/dashboardRosterApi";
import { adminBootstrapKey } from "../../types/admin";
import type {
  DashboardRosterPlan,
  DashboardRosterSession,
  RosterGenerationRound,
  RosterSupervisionKind,
} from "../../types/roster";

const props = defineProps<{ plan: DashboardRosterPlan }>();
const emit = defineEmits<{
  changed: [warnings: Array<{ code: string; message: string }>];
}>();

const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken = bootstrap.rosterSessionCsrfToken;

interface Slot extends RosterGenerationRound {
  key: string;
}

const selectedSlot = ref<Slot | null>(null);
const selectedSessionId = ref<number | null>(null);
const moduleKey = ref("");
const location = ref("");
const groupIds = ref<number[]>([]);
const staffIds = ref<number[]>([]);
const staffGroupId = ref<number | null>(null);
const supervisionKind = ref<RosterSupervisionKind>("staff");
const cookStaffId = ref<number | null>(props.plan.planning.staffingSettings.cookStaffId);
const hasVi = computed(() =>
  props.plan.planning.modules.some((module) => module.key === "Voedsel-Innovatie"),
);
watch(
  hasVi,
  (enabled) => {
    if (!enabled) cookStaffId.value = null;
  },
  { immediate: true },
);
const saving = ref(false);
const deleting = ref(false);
const confirmDelete = ref(false);
const error = ref<string | null>(null);
const softWarnings = ref<Array<{ code: string; message: string }>>([]);

type CellEditorMode = "full" | "module" | "staff";
const cellEditorMode = ref<CellEditorMode>("full");

const slots = computed<Slot[]>(() => {
  const byKey = new Map<string, Slot>();

  const timelineRounds = (props.plan.planning.rounds ?? []).filter((block) => block.type === "round");
  const preservedRounds = timelineRounds.length > 0
    ? timelineRounds
    : props.plan.planning.generation.defaultRounds;
  for (const round of preservedRounds) {
    const key = `${round.startTime}-${round.endTime}`;
    byKey.set(key, { ...round, key });
  }
  // Keep existing exceptional session intervals visible as well as empty rounds.
  for (const session of props.plan.planning.sessions) {
    const key = `${session.startTime}-${session.endTime}`;
    if (!byKey.has(key)) {
      byKey.set(key, {
        key,
        label: key,
        startTime: session.startTime,
        endTime: session.endTime,
      });
    }
  }

  return [...byKey.values()].sort((a, b) => a.startTime.localeCompare(b.startTime));
});

type ManualRow =
  | { kind: "activity"; key: string; slot: Slot }
  | { kind: "neutral"; key: string; startTime: string; endTime: string; label: string };

function rowMinutes(value: string): number {
  const [hour, minute] = value.split(":").map(Number);
  return ((hour ?? 0) * 60) + (minute ?? 0);
}

const manualRows = computed<ManualRow[]>(() => {
  if (slots.value.length === 0) return [];

  const timeline = props.plan.planning.rounds ?? [];
  if (timeline.length > 0) {
    const rows: ManualRow[] = slots.value.map((slot) => ({ kind: "activity", key: slot.key, slot }));
    timeline.forEach((block, index) => {
      if (block.type !== "round") {
        rows.push({ kind: "neutral", key: "timeline-" + index, startTime: block.startTime, endTime: block.endTime, label: block.label });
      }
    });
    return rows.sort((left, right) =>
      (left.kind === "activity" ? left.slot.startTime : left.startTime)
        .localeCompare(right.kind === "activity" ? right.slot.startTime : right.startTime),
    );
  }

  const rows: ManualRow[] = [];
  const first = slots.value[0];

  if (first && rowMinutes(first.startTime) > rowMinutes("10:00")) {
    rows.push({
      kind: "neutral",
      key: "arrival",
      startTime: "10:00",
      endTime: first.startTime,
      label: "Aankomst & welkom",
    });
  }

  slots.value.forEach((slot, index) => {
    rows.push({ kind: "activity", key: slot.key, slot });

    const next = slots.value[index + 1];
    if (!next) return;

    const gap = rowMinutes(next.startTime) - rowMinutes(slot.endTime);
    if (gap <= 0) return;

    rows.push({
      kind: "neutral",
      key: `gap-${slot.endTime}-${next.startTime}`,
      startTime: slot.endTime,
      endTime: next.startTime,
      label:
        rowMinutes(slot.endTime) >= rowMinutes("12:15") && gap >= 25
          ? "Lunch"
          : "Pauze",
    });
  });

  const last = slots.value.at(-1);
  const programEnd = last && rowMinutes(last.endTime) <= rowMinutes("12:15")
    ? "12:15"
    : "15:00";

  if (last && rowMinutes(last.endTime) < rowMinutes(programEnd)) {
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

const staffLetterMap = computed(() => {
  const result = new Map<number, string>();
  const ids = props.plan.planning.selectedStaffIds.filter((id) => {
    if (hasVi.value) return true;     const member = props.plan.planning.staffCatalog.find((candidate) => candidate.id === id);     return member?.canGuide !== false;   });

  props.plan.planning.sessions.forEach((session) => {
    session.staffAssignments.forEach((staff) => {
      if (!ids.includes(staff.id)) ids.push(staff.id);
    });
  });

  ids.forEach((id, index) => {
    result.set(id, String.fromCharCode(65 + index));
  });

  return result;
});

const staffLegend = computed(() =>
  props.plan.planning.staffCatalog
    .filter((member) => staffLetterMap.value.has(member.id))
    .map((member) => ({
      id: member.id,
      code: staffLetterMap.value.get(member.id) ?? "?",
      name: member.name,
      employmentType: member.employmentType,
    }))
    .sort((left, right) => left.code.localeCompare(right.code)),
);

const cookLegend = computed(() => {
  if (!hasVi.value) return null;
  const ids = new Set(props.plan.planning.sessions.filter(s => s.moduleKey === 'Voedsel-Innovatie').map(s => s.cookStaffId ?? props.plan.planning.staffingSettings.cookStaffId));
  if (!ids.size) return null;
  const names = props.plan.planning.staffCatalog.filter(m => ids.has(m.id)).map(m => m.name);
  return names.length ? { name: names.join(' / ') + (names.length > 1 ? ' (per lesblok)' : '') } : null;
});
function staffAssignmentsForGroup(
  session: DashboardRosterSession,
  groupId: number,
) {
  const groupAssignment = (session.groupStaffAssignments ?? []).find(
    (assignment) => assignment.groupId === groupId,
  );

  return groupAssignment?.staffAssignments ?? session.staffAssignments;
}

function staffLetters(session: DashboardRosterSession | null, groupId: number): string {
  if (!session) return "Personeel open";
  if (session.groupSupervision?.some((group) => group.groupId === groupId && group.kind === "school")) return "School";

  const guideLetters = staffAssignmentsForGroup(session, groupId)
    .map((staff) => staffLetterMap.value.get(staff.id) ?? "?")
    .join(" / ");

  if (
    session.moduleKey === "Voedsel-Innovatie"
    && props.plan.planning.staffingSettings.cookStaffId !== null
  ) {
    return guideLetters ? `KOK / ${guideLetters}` : "KOK / OPEN";
  }

  return guideLetters || "OPEN";
}

const currentSession = computed<DashboardRosterSession | null>(() => {
  if (selectedSessionId.value === null) return null;
  return props.plan.planning.sessions.find((session) => session.id === selectedSessionId.value) ?? null;
});

const selectedStaffGroup = computed(() =>
  staffGroupId.value === null
    ? null
    : props.plan.groups.find((group) => group.id === staffGroupId.value) ?? null,
);

const currentModule = computed(() =>
  props.plan.planning.modules.find((module) => module.key === moduleKey.value) ?? null,
);

const cookCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter(
    (member) => member.isActive && member.canCook,
  ),
);

function modulePreferenceRank(member: DashboardRosterPlan["planning"]["staffCatalog"][number]): number {
  return member.preferences.find((preference) => preference.moduleKey === moduleKey.value)?.rank
    ?? Number.MAX_SAFE_INTEGER;
}

function modulePreferenceLabel(member: DashboardRosterPlan["planning"]["staffCatalog"][number]): string {
  const rank = modulePreferenceRank(member);
  if (!Number.isFinite(rank) || rank === Number.MAX_SAFE_INTEGER) return "";
  return rank === 1 ? "1e algemene voorkeur" : `${rank}e algemene voorkeur`;
}

const eligibleStaff = computed(() =>
  props.plan.planning.staffCatalog
    .filter(
      (member) =>
        member.isActive
        && member.canGuide
        && member.preferences.some((preference) => preference.moduleKey === moduleKey.value),
    )
    .sort((left, right) => {
      const rankDifference = modulePreferenceRank(left) - modulePreferenceRank(right);
      return rankDifference !== 0 ? rankDifference : left.name.localeCompare(right.name, "nl");
    }),
);

function sessionFor(slot: Slot, groupId: number): DashboardRosterSession | null {
  return props.plan.planning.sessions.find(
    (session) =>
      session.startTime === slot.startTime
      && session.endTime === slot.endTime
      && session.groupIds.includes(groupId),
  ) ?? null;
}


function openCell(slot: Slot, groupId: number): void {
  cellEditorMode.value = "full";
  const session = sessionFor(slot, groupId);
  if (session) {
    openExisting(session, slot);
    return;
  }
  openNew(slot, groupId);
}

function openQuickModule(session: DashboardRosterSession | null, slot: Slot): void {
  if (!session) return;
  openExisting(session, slot);
  cellEditorMode.value = "module";
}

function openQuickStaff(
  session: DashboardRosterSession | null,
  slot: Slot,
  groupId: number,
): void {
  if (!session) return;
  openExisting(session, slot, groupId);
  cellEditorMode.value = "staff";
}

function openFullEditor(): void {
  cellEditorMode.value = "full";
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

function openExisting(
  session: DashboardRosterSession,
  slot: Slot,
  targetGroupId: number | null = null,
): void {
  selectedSlot.value = slot;
  selectedSessionId.value = session.id;
  staffGroupId.value = targetGroupId;
  moduleKey.value = session.moduleKey;
  location.value = session.location ?? "";
  groupIds.value = [...session.groupIds];
  staffIds.value = targetGroupId === null
    ? session.staffAssignments.map((staff) => staff.id)
    : staffAssignmentsForGroup(session, targetGroupId).map((staff) => staff.id);
  supervisionKind.value = session.groupSupervision?.find((group) => group.groupId === targetGroupId)?.kind
    ?? (staffIds.value.length ? "staff" : "open");
  error.value = null;
  cookStaffId.value = hasVi.value ? (session.cookStaffId ?? props.plan.planning.staffingSettings.cookStaffId) : null;
  softWarnings.value = [];
  confirmDelete.value = false;
}

function openNew(slot: Slot, groupId: number): void {
  selectedSlot.value = slot;
  selectedSessionId.value = null;
  staffGroupId.value = null;
  moduleKey.value = props.plan.planning.modules[0]?.key ?? "";
  location.value = props.plan.planning.modules[0]?.defaultLocation ?? "";
  groupIds.value = [groupId];
  staffIds.value = [];
  cookStaffId.value = hasVi.value ? props.plan.planning.staffingSettings.cookStaffId : null;
  error.value = null;
  softWarnings.value = [];
  confirmDelete.value = false;
}

function closeEditor(): void {
  selectedSlot.value = null;
  selectedSessionId.value = null;
  staffGroupId.value = null;
  moduleKey.value = "";
  location.value = "";
  groupIds.value = [];
  staffIds.value = [];
  cookStaffId.value = hasVi.value ? props.plan.planning.staffingSettings.cookStaffId : null;
  error.value = null;
  softWarnings.value = [];
  confirmDelete.value = false;
  cellEditorMode.value = "full";
}

function toggleGroup(groupId: number): void {
  groupIds.value = groupIds.value.includes(groupId)
    ? groupIds.value.filter((id) => id !== groupId)
    : [...groupIds.value, groupId];
}

function toggleStaff(staffId: number): void {
  staffIds.value = staffIds.value.includes(staffId)
    ? staffIds.value.filter((id) => id !== staffId)
    : [...staffIds.value, staffId];
}

watch(moduleKey, () => {
  // Existing invalid assignments remain visible until explicitly removed.

  if (
    currentModule.value?.defaultLocation
    && (
      selectedSessionId.value === null
      || cellEditorMode.value === "module"
    )
  ) {
    location.value = currentModule.value.defaultLocation;
  }
});

async function save(): Promise<void> {
  if (!selectedSlot.value || saving.value) return;
  error.value = null;
  softWarnings.value = [];

  if (!moduleKey.value) {
    error.value = "Kies een module.";
    return;
  }
  if (groupIds.value.length === 0) {
    error.value = "Kies minimaal één groep.";
    return;
  }

  if (cellEditorMode.value === "staff" && staffGroupId.value !== null) {
    if (supervisionKind.value === "staff" && staffIds.value.length === 0) {
      error.value = "Kies minimaal één begeleider voor deze groep.";
      return;
    }

  }

  saving.value = true;
  try {
    const result = await saveRosterSession(
      {
        planId: props.plan.id,
        sessionId: selectedSessionId.value,
        expectedRevision: props.plan.revision,
        moduleKey: moduleKey.value,
        startTime: selectedSlot.value.startTime,
        endTime: selectedSlot.value.endTime,
        location: location.value.trim() || null,
        groupIds: groupIds.value,
        staffIds: cellEditorMode.value === "staff" && supervisionKind.value !== "staff" ? [] : staffIds.value,
        staffGroupId: cellEditorMode.value === "staff" ? staffGroupId.value : null,
        ...(cellEditorMode.value === "staff" ? { supervisionKind: supervisionKind.value } : {}),
        cookStaffId: hasVi.value ? cookStaffId.value : null,
      },
      csrfToken,
    );

    softWarnings.value = result.warnings ?? [];
    emit("changed", softWarnings.value);
    closeEditor();
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "De roostercel kon niet worden opgeslagen.";
  } finally {
    saving.value = false;
  }
}

async function remove(): Promise<void> {
  if (selectedSessionId.value === null || deleting.value) return;
  error.value = null;
  deleting.value = true;

  try {
    await deleteRosterSession(
      props.plan.id,
      selectedSessionId.value,
      props.plan.revision,
      csrfToken,
    );
    emit("changed", []);
    closeEditor();
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "De roostersessie kon niet worden verwijderd.";
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <section class="admin-roster-manual-workspace">
    <section class="admin-card">
      <div class="admin-roster-day__heading">
        <div>
          <p class="admin-eyebrow">Bewerkbare bron</p>
          <h2>Lesrooster handmatig corrigeren</h2>
        </div>
        <p>
          Klik een cel om module, groepen, docent(en) of locatie te wijzigen.
          Tijden zijn hier vast en worden alleen in de wizard aangepast.
        </p>
      </div>

      <div
        v-if="staffLegend.length || cookLegend"
        class="admin-roster-staff-code-legend"
        aria-label="Legenda begeleiderscodes"
      >
        <strong>Begeleiderscodes</strong>
        <span v-for="member in staffLegend" :key="member.id">
          <b>{{ member.code }}</b> = {{ member.name }}
        </span>
        <span v-if="cookLegend">
          <b>Kok</b> = {{ cookLegend.name }}
        </span>
      </div>
      <div class="admin-roster-day__wrap">
        <table class="admin-roster-grid-table admin-roster-edit-grid">
          <thead>
            <tr>
              <th>Tijd</th>
              <th v-for="group in plan.groups" :key="group.id">{{ group.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in manualRows" :key="row.key">
              <th
                class="admin-roster-day-table__time"
                :class="row.kind === 'neutral' ? 'is-neutral' : 'is-activity'"
              >
                <strong>{{ row.kind === "activity" ? row.slot.startTime : row.startTime }}</strong>
                <span>{{ row.kind === "activity" ? row.slot.endTime : row.endTime }}</span>
              </th>

              <td
                v-if="row.kind === 'neutral'"
                :colspan="plan.groups.length"
                class="admin-roster-grid-neutral admin-roster-grid-neutral--readonly"
              >
                {{ row.label }}
              </td>

              <template v-else>
                <td
                  v-for="group in plan.groups"
                  :key="group.id"
                  class="admin-roster-edit-cell"
                  :class="{ 'is-empty': !sessionFor(row.slot, group.id) }"
                  :style="
                    sessionFor(row.slot, group.id)
                      ? {
                          backgroundColor: sessionFor(row.slot, group.id)?.color,
                          color: textColor(sessionFor(row.slot, group.id)?.color ?? '#ffffff'),
                        }
                      : undefined
                  "
                >
                  <div
                    v-if="sessionFor(row.slot, group.id)"
                    class="admin-roster-edit-cell__content"
                  >
                    <button
                      type="button"
                      class="admin-roster-edit-cell__quick admin-roster-edit-cell__quick--module"
                      data-action-label="Module aanpassen"
                      title="Module snel wijzigen"
                      @click="openQuickModule(sessionFor(row.slot, group.id), row.slot)"
                    >
                      <strong>{{ sessionFor(row.slot, group.id)?.moduleLabel }}</strong>
                    </button>

                    <button
                      type="button"
                      class="admin-roster-edit-cell__quick admin-roster-edit-cell__quick--staff"
                      data-action-label="Begeleider aanpassen"
                      title="Begeleider(s) snel wijzigen"
                      @click="openQuickStaff(sessionFor(row.slot, group.id), row.slot, group.id)"
                    >
                      <span class="admin-roster-edit-cell__staff-code">
                        {{ staffLetters(sessionFor(row.slot, group.id), group.id) }}
                      </span>
                    </button>

                    <button
                      type="button"
                      class="admin-roster-edit-cell__full"
                      title="Volledig bewerken"
                      :aria-label="`Volledig bewerken: ${sessionFor(row.slot, group.id)?.moduleLabel ?? 'sessie'}`"
                      @click="openCell(row.slot, group.id)"
                    >
                      <Pencil :size="15" :stroke-width="2.4" aria-hidden="true" />
                    </button>
                  </div>

                  <button
                    v-else
                    type="button"
                    class="admin-roster-edit-cell__add"
                    :aria-label="`Sessie toevoegen voor ${group.label} om ${row.slot.startTime}`"
                    @click="openCell(row.slot, group.id)"
                  >
                    <span aria-hidden="true">+</span>
                    <small>Toevoegen</small>
                  </button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <aside v-if="selectedSlot" class="admin-card admin-roster-cell-editor">
      <div class="admin-roster-cell-editor__heading">
        <div>
          <p class="admin-eyebrow">
            {{ cellEditorMode === "module"
              ? "Module snel wijzigen"
              : cellEditorMode === "staff"
                ? `Begeleider snel wijzigen${selectedStaffGroup ? ` · ${selectedStaffGroup.label}` : ""}`
                : "Cel beheren" }}
          </p>
          <h3>{{ currentSession ? currentSession.moduleLabel : "Nieuwe sessie" }}</h3>
        </div>
        <button type="button" class="admin-roster-cell-editor__close" @click="closeEditor">×</button>
      </div>

      <div class="admin-roster-cell-editor__time">
        <span>Tijd — alleen-lezen</span>
        <strong>{{ selectedSlot.startTime }}–{{ selectedSlot.endTime }}</strong>
      </div>

      <label v-if="cellEditorMode !== 'staff'" class="admin-field">
        <span class="admin-field__label">Module</span>
        <select v-model="moduleKey" class="admin-control">
          <option v-for="module in plan.planning.modules" :key="module.key" :value="module.key">
            {{ module.label }}
          </option>
        </select>
      </label>

      <label v-if="cellEditorMode === 'full'" class="admin-field">
        <span class="admin-field__label">Locatie / gebouw</span>
        <input v-model="location" class="admin-control" type="text" maxlength="160">
      </label>

      <fieldset v-if="cellEditorMode === 'full'" class="admin-roster-cell-editor__fieldset">
        <legend>Groepen</legend>
        <label v-for="group in plan.groups" :key="group.id">
          <input
            type="checkbox"
            :checked="groupIds.includes(group.id)"
            @change="toggleGroup(group.id)"
          >
          {{ group.label }}
        </label>
      </fieldset>

      <label v-if="cellEditorMode === 'full' && hasVi" class="admin-field">
        <span class="admin-field__label">Kok Voedsel Innovatie — dagrol</span>
        <select v-model="cookStaffId" class="admin-control">
          <option :value="null">Geen kok ingevuld</option>
          <option v-if="cookStaffId !== null && !cookCatalog.some(member => member.id === cookStaffId)" :value="cookStaffId" disabled>
            {{ props.plan.planning.staffCatalog.find(member => member.id === cookStaffId)?.name ?? 'Bestaande kok' }} — niet meer bevoegd/actief
          </option>
          <option v-for="member in cookCatalog" :key="member.id" :value="member.id">
            {{ member.name }}{{ !member.canGuide ? " — alleen kok" : "" }}
          </option>
        </select>
        <span class="admin-field__help">
          Deze keuze geldt voor alle VI-tijdvakken van deze roosterdag.
        </span>
      </label>

      <label v-if="cellEditorMode === 'staff'" class="admin-field">
        <span class="admin-field__label">Begeleiding voor deze groep</span>
        <select v-model="supervisionKind" class="admin-control">
          <option value="staff">GeoFort-personeel</option>
          <option v-if="currentModule?.schoolSupervisionAllowed" value="school">School — expliciet bevestigd</option>
          <option value="open">OPEN — nog onbezet</option>
        </select>
        <span class="admin-field__help">Schoolbegeleiding geldt alleen voor deze groep in deze sessie.</span>
      </label>
      <fieldset v-if="cellEditorMode !== 'module' && (cellEditorMode !== 'staff' || supervisionKind === 'staff')" class="admin-roster-cell-editor__fieldset">
        <legend>
          Docenten / begeleiders
          <template v-if="cellEditorMode === 'staff' && selectedStaffGroup">
            voor {{ selectedStaffGroup.label }}
          </template>
        </legend>
        <p v-if="eligibleStaff.length === 0" class="admin-field__help">
          Geen actieve medewerker in het personeelsbestand kan deze module geven.
        </p>
        <p v-for="id in staffIds.filter(value => !eligibleStaff.some(member => member.id === value))" :key="'invalid-' + id" class="admin-inline-error">
          {{ props.plan.planning.staffCatalog.find(member => member.id === id)?.name ?? 'Medewerker #' + id }} is niet meer actief of bevoegd voor deze module.
          <AdminButton variant="secondary" @click="toggleStaff(id)">Toewijzing verwijderen</AdminButton>
        </p>
        <label v-for="member in eligibleStaff" :key="member.id">
          <input
            type="checkbox"
            :checked="staffIds.includes(member.id)"
            @change="toggleStaff(member.id)"
          >
          <span>
            {{ member.name }}
            <small>· {{ member.employmentType === "volunteer" ? "vrijwilliger" : "betaald" }}</small>
            <small class="admin-roster-staff-preference-rank">
              · {{ modulePreferenceLabel(member) }}
            </small>
          </span>
        </label>
      </fieldset>

      <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

      <div v-if="softWarnings.length" class="admin-inline-notice admin-inline-notice--warning">
        <strong>Opgeslagen met zachte waarschuwing</strong>
        <ul>
          <li v-for="warning in softWarnings" :key="warning.code">{{ warning.message }}</li>
        </ul>
      </div>

      <div class="admin-roster-cell-editor__actions">
        <AdminButton :loading="saving" @click="save">
          {{ cellEditorMode === "full" ? "Opslaan" : "Snelle wijziging opslaan" }}
        </AdminButton>

        <AdminButton
          v-if="cellEditorMode !== 'full'"
          variant="secondary"
          @click="openFullEditor"
        >
          Volledig bewerken
        </AdminButton>

        <template v-if="selectedSessionId !== null && cellEditorMode === 'full'">
          <AdminButton
            v-if="!confirmDelete"
            variant="danger"
            @click="confirmDelete = true"
          >
            ðŸ—‘ Verwijderen
          </AdminButton>
          <AdminButton
            v-else
            variant="danger"
            :loading="deleting"
            @click="remove"
          >
            Bevestig verwijderen
          </AdminButton>
        </template>

        <AdminButton variant="ghost" @click="closeEditor">Sluiten</AdminButton>
      </div>
    </aside>
  </section>
</template>
