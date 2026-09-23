<script setup lang="ts">
import { computed } from "vue";

import type {
  DashboardRosterGroup,
  DashboardRosterSession,
  RosterModuleOption,
} from "../../types/roster";

const props = defineProps<{
  groups: DashboardRosterGroup[];
  modules: RosterModuleOption[];
  sessions: DashboardRosterSession[];
}>();

const byGroupAndModule = computed(() => {
  const result = new Map<string, DashboardRosterSession>();

  for (const session of props.sessions) {
    for (const groupId of session.groupIds) {
      result.set(`${groupId}:${session.moduleKey}`, session);
    }
  }

  return result;
});

function sessionFor(groupId: number, moduleKey: string): DashboardRosterSession | null {
  return byGroupAndModule.value.get(`${groupId}:${moduleKey}`) ?? null;
}

function completedCount(groupId: number): number {
  return props.modules.filter((module) => sessionFor(groupId, module.key) !== null).length;
}
</script>

<template>
  <section class="admin-card admin-roster-matrix-section">
    <div class="admin-roster-matrix-section__heading">
      <div>
        <p class="admin-eyebrow">Controleweergave</p>
        <h2>Programmadekking per groep</h2>
      </div>
      <p>Elke groep moet elke programmamodule precies een keer krijgen.</p>
    </div>

    <div class="admin-roster-matrix-wrap">
      <table class="admin-roster-matrix">
        <thead>
          <tr>
            <th scope="col">Groep</th>
            <th v-for="module in modules" :key="module.key" scope="col">
              <span
                class="admin-roster-module-dot"
                :style="{ '--roster-module-color': module.color }"
                aria-hidden="true"
              />
              {{ module.label }}
            </th>
            <th scope="col">Voortgang</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="group in groups" :key="group.id">
            <th scope="row">{{ group.label }}</th>

            <td v-for="module in modules" :key="module.key">
              <template v-if="sessionFor(group.id, module.key)">
                <strong>
                  {{ sessionFor(group.id, module.key)?.startTime }}
                </strong>
                <span class="admin-roster-matrix__location">
                  {{ sessionFor(group.id, module.key)?.location ?? "Locatie open" }}
                </span>
              </template>
              <span v-else class="admin-roster-matrix__empty">Nog open</span>
            </td>

            <td>
              <strong>{{ completedCount(group.id) }}/{{ modules.length }}</strong>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>