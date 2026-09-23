<script setup lang="ts">
import { computed, inject, ref, watch } from "vue";

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
const cookStaffId = ref<number | null>(props.plan.planning.staffingSettings.cookStaffId);
const saving = ref(false);
const deleting = ref(false);
const confirmDelete = ref(false);
const error = ref<string | null>(null);
const softWarnings = ref<Array<{ code: string; message: string }>>([]);

const slots = computed<Slot[]>(() => {
  const byKey = new Map<string, Slot>();

  if (props.plan.planning.sessions.length === 0) {
    for (const round of props.plan.planning.generation.defaultRounds) {
      const key = `${round.startTime}-${round.endTime}`;
      byKey.set(key, { ...round, key });
    }
  } else {
    for (const session of props.plan.planning.sessions) {
      const key = `${session.startTime}-${session.endTime}`;
      if (!byKey.has(key)) {
        byKey.set(key, {
          key,
          label: `${session.startTime}-${session.endTime}`,
          startTime: session.startTime,
          endTime: session.endTime,
        });
      }
    }
  }

  return [...byKey.values()].sort((a, b) => a.startTime.localeCompare(b.startTime));
});

const currentSession = computed<DashboardRosterSession | null>(() => {
  if (selectedSessionId.value === null) return null;
  return props.plan.planning.sessions.find((session) => session.id === selectedSessionId.value) ?? null;
});

const currentModule = computed(() =>
  props.plan.planning.modules.find((module) => module.key === moduleKey.value) ?? null,
);

const cookCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter(
    (member) => member.isActive && member.canCook,
  ),
);

const eligibleStaff = computed(() =>
  props.plan.planning.staffCatalog.filter(
    (member) =>
      member.isActive
      && member.canGuide
      && member.preferences.some((preference) => preference.moduleKey === moduleKey.value),
  ),
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
  const session = sessionFor(slot, groupId);
  if (session) {
    openExisting(session, slot);
    return;
  }
  openNew(slot, groupId);
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

function openExisting(session: DashboardRosterSession, slot: Slot): void {
  selectedSlot.value = slot;
  selectedSessionId.value = session.id;
  moduleKey.value = session.moduleKey;
  location.value = session.location ?? "";
  groupIds.value = [...session.groupIds];
  staffIds.value = session.staffAssignments.map((staff) => staff.id);
  error.value = null;
  cookStaffId.value = props.plan.planning.staffingSettings.cookStaffId;
  softWarnings.value = [];
  confirmDelete.value = false;
}

function openNew(slot: Slot, groupId: number): void {
  selectedSlot.value = slot;
  selectedSessionId.value = null;
  moduleKey.value = props.plan.planning.modules[0]?.key ?? "";
  location.value = props.plan.planning.modules[0]?.defaultLocation ?? "";
  groupIds.value = [groupId];
  staffIds.value = [];
  cookStaffId.value = props.plan.planning.staffingSettings.cookStaffId;
  error.value = null;
  softWarnings.value = [];
  confirmDelete.value = false;
}

function closeEditor(): void {
  selectedSlot.value = null;
  selectedSessionId.value = null;
  moduleKey.value = "";
  location.value = "";
  groupIds.value = [];
  staffIds.value = [];
  cookStaffId.value = props.plan.planning.staffingSettings.cookStaffId;
  error.value = null;
  softWarnings.value = [];
  confirmDelete.value = false;
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
  const eligibleIds = new Set(eligibleStaff.value.map((member) => member.id));
  staffIds.value = staffIds.value.filter((id) => eligibleIds.has(id));

  if (currentModule.value?.defaultLocation && selectedSessionId.value === null) {
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
        staffIds: staffIds.value,
        cookStaffId: cookStaffId.value,
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

      <div class="admin-roster-day__wrap">
        <table class="admin-roster-grid-table admin-roster-edit-grid">
          <thead>
            <tr>
              <th>Tijd</th>
              <th v-for="group in plan.groups" :key="group.id">{{ group.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="slot in slots" :key="slot.key">
              <th class="admin-roster-day-table__time">
                <strong>{{ slot.startTime }}</strong>
                <span>{{ slot.endTime }}</span>
              </th>

              <td
                v-for="group in plan.groups"
                :key="group.id"
                class="admin-roster-edit-cell"
                :class="{ 'is-empty': !sessionFor(slot, group.id) }"
                :style="
                  sessionFor(slot, group.id)
                    ? {
                        backgroundColor: sessionFor(slot, group.id)?.color,
                        color: textColor(sessionFor(slot, group.id)?.color ?? '#ffffff'),
                      }
                    : undefined
                "
              >
                <button
                  v-if="sessionFor(slot, group.id)"
                  type="button"
                  class="admin-roster-edit-cell__button"
                  @click="openCell(slot, group.id)"
                >
                  <strong>{{ sessionFor(slot, group.id)?.moduleLabel }}</strong>
                  <span>{{ sessionFor(slot, group.id)?.staffAssignments.map((staff) => staff.name).join(", ") || "Personeel open" }}</span>
                  <small>{{ sessionFor(slot, group.id)?.location ?? "Locatie open" }}</small>
                </button>

                <button
                  v-else
                  type="button"
                  class="admin-roster-edit-cell__add"
                  :aria-label="`Sessie toevoegen voor ${group.label} om ${slot.startTime}`"
                  @click="openCell(slot, group.id)"
                >
                  <span aria-hidden="true">+</span>
                  <small>Toevoegen</small>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <aside v-if="selectedSlot" class="admin-card admin-roster-cell-editor">
      <div class="admin-roster-cell-editor__heading">
        <div>
          <p class="admin-eyebrow">Cel beheren</p>
          <h3>{{ currentSession ? currentSession.moduleLabel : "Nieuwe sessie" }}</h3>
        </div>
        <button type="button" class="admin-roster-cell-editor__close" @click="closeEditor">×</button>
      </div>

      <div class="admin-roster-cell-editor__time">
        <span>Tijd — alleen-lezen</span>
        <strong>{{ selectedSlot.startTime }}–{{ selectedSlot.endTime }}</strong>
      </div>

      <label class="admin-field">
        <span class="admin-field__label">Module</span>
        <select v-model="moduleKey" class="admin-control">
          <option v-for="module in plan.planning.modules" :key="module.key" :value="module.key">
            {{ module.label }}
          </option>
        </select>
      </label>

      <label class="admin-field">
        <span class="admin-field__label">Locatie / gebouw</span>
        <input v-model="location" class="admin-control" type="text" maxlength="160">
      </label>

      <fieldset class="admin-roster-cell-editor__fieldset">
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

      <label class="admin-field">
        <span class="admin-field__label">Kok Voedsel Innovatie — dagrol</span>
        <select v-model="cookStaffId" class="admin-control">
          <option :value="null">Geen kok ingevuld</option>
          <option v-for="member in cookCatalog" :key="member.id" :value="member.id">
            {{ member.name }}{{ !member.canGuide ? " — alleen kok" : "" }}
          </option>
        </select>
        <span class="admin-field__help">
          Deze keuze geldt voor alle VI-tijdvakken van deze roosterdag.
        </span>
      </label>

      <fieldset class="admin-roster-cell-editor__fieldset">
        <legend>Docenten / begeleiders</legend>
        <p v-if="eligibleStaff.length === 0" class="admin-field__help">
          Geen actieve medewerker in het personeelsbestand kan deze module geven.
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
        <AdminButton :loading="saving" @click="save">Opslaan</AdminButton>

        <template v-if="selectedSessionId !== null">
          <AdminButton
            v-if="!confirmDelete"
            variant="danger"
            @click="confirmDelete = true"
          >
            🗑 Verwijderen
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
