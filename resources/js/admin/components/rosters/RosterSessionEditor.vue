<script setup lang="ts">
import { computed, inject, ref, watch } from "vue";

import AdminButton from "../form/AdminButton.vue";
import { saveRosterSession } from "../../services/dashboardRosterApi";
import { adminBootstrapKey } from "../../types/admin";
import type {
  DashboardRosterGroup,
  DashboardRosterSession,
  RosterModuleOption,
} from "../../types/roster";

const props = defineProps<{
  planId: number;
  revision: number;
  groups: DashboardRosterGroup[];
  modules: RosterModuleOption[];
  session: DashboardRosterSession | null;
}>();

const emit = defineEmits<{
  saved: [];
  cancel: [];
}>();

const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken = bootstrap.rosterSessionCsrfToken;

const moduleKey = ref("");
const startTime = ref("10:00");
const endTime = ref("10:45");
const location = ref("");
const groupIds = ref<number[]>([]);
const saving = ref(false);
const error = ref<string | null>(null);

const editing = computed(() => props.session !== null);
const selectedModule = computed(
  () => props.modules.find((module) => module.key === moduleKey.value) ?? null,
);

watch(
  () => props.session,
  (session) => {
    error.value = null;

    if (session) {
      moduleKey.value = session.moduleKey;
      startTime.value = session.startTime;
      endTime.value = session.endTime;
      location.value = session.location ?? "";
      groupIds.value = [...session.groupIds];
      return;
    }

    moduleKey.value = props.modules[0]?.key ?? "";
    startTime.value = "10:00";
    endTime.value = "10:45";
    location.value = props.modules[0]?.defaultLocation ?? "";
    groupIds.value = [];
  },
  { immediate: true },
);

watch(moduleKey, (next, previous) => {
  const previousDefault = props.modules.find((module) => module.key === previous)?.defaultLocation ?? "";
  const nextDefault = props.modules.find((module) => module.key === next)?.defaultLocation ?? "";

  if (location.value === "" || location.value === previousDefault) {
    location.value = nextDefault;
  }
});

function toggleGroup(groupId: number): void {
  groupIds.value = groupIds.value.includes(groupId)
    ? groupIds.value.filter((id) => id !== groupId)
    : [...groupIds.value, groupId];
}

async function submit(): Promise<void> {
  if (saving.value) return;

  saving.value = true;
  error.value = null;

  try {
    await saveRosterSession(
      {
        planId: props.planId,
        sessionId: props.session?.id ?? null,
        expectedRevision: props.revision,
        moduleKey: moduleKey.value,
        startTime: startTime.value,
        endTime: endTime.value,
        location: location.value.trim() || null,
        groupIds: groupIds.value,
      },
      csrfToken,
    );

    emit("saved");
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "De roostersessie kon niet worden opgeslagen.";
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="admin-card admin-roster-editor">
    <div class="admin-roster-editor__heading">
      <div>
        <p class="admin-eyebrow">{{ editing ? "Sessie wijzigen" : "Nieuwe sessie" }}</p>
        <h2>{{ editing ? session?.moduleLabel : "Activiteit plannen" }}</h2>
      </div>

      <AdminButton
        v-if="editing"
        variant="ghost"
        :disabled="saving"
        @click="emit('cancel')"
      >
        Annuleren
      </AdminButton>
    </div>

    <p>
      Een sessie kan meerdere groepen tegelijk bevatten. De parallelregel telt sessies,
      niet het aantal groepen binnen een sessie.
    </p>

    <p v-if="error" class="admin-inline-error" role="alert">
      {{ error }}
    </p>

    <div class="admin-roster-editor__grid">
      <label class="admin-field">
        <span class="admin-field__label">Module</span>
        <select v-model="moduleKey" class="admin-control">
          <option v-for="module in modules" :key="module.key" :value="module.key">
            {{ module.label }}
          </option>
        </select>
      </label>

      <label class="admin-field">
        <span class="admin-field__label">Starttijd</span>
        <input v-model="startTime" class="admin-control" type="time" step="300">
      </label>

      <label class="admin-field">
        <span class="admin-field__label">Eindtijd</span>
        <input v-model="endTime" class="admin-control" type="time" step="300">
      </label>

      <label class="admin-field admin-roster-editor__location">
        <span class="admin-field__label">Locatie</span>
        <input
          v-model="location"
          class="admin-control"
          type="text"
          maxlength="160"
          :placeholder="selectedModule?.defaultLocation ?? 'Locatie invullen'"
        >
      </label>
    </div>

    <fieldset class="admin-roster-editor__groups">
      <legend>Roostergroepen</legend>
      <div class="admin-roster-group-options">
        <label
          v-for="group in groups"
          :key="group.id"
          class="admin-roster-group-option"
          :class="{ 'is-selected': groupIds.includes(group.id) }"
        >
          <input
            type="checkbox"
            :checked="groupIds.includes(group.id)"
            @change="toggleGroup(group.id)"
          >
          <span>{{ group.label }}</span>
        </label>
      </div>
    </fieldset>

    <div v-if="selectedModule" class="admin-roster-editor__rule">
      <span
        class="admin-roster-module-dot"
        :style="{ '--roster-module-color': selectedModule.color }"
        aria-hidden="true"
      />
      {{ selectedModule.label }}: maximaal {{ selectedModule.maxParallel }}
      parallelle {{ selectedModule.maxParallel === 1 ? "sessie" : "sessies" }}.
    </div>

    <AdminButton :loading="saving" @click="submit">
      {{ editing ? "Wijzigingen opslaan" : "Sessie toevoegen" }}
    </AdminButton>
  </section>
</template>