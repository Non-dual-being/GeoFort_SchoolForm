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

const coverage = computed(() => {
  const counts = new Map<string, number>();

  for (const session of props.sessions) {
    for (const groupId of session.groupIds) {
      const key = `${groupId}:${session.moduleKey}`;
      counts.set(key, (counts.get(key) ?? 0) + 1);
    }
  }

  let completeGroups = 0;
  let missingAssignments = 0;
  let duplicateAssignments = 0;

  for (const group of props.groups) {
    let complete = true;

    for (const module of props.modules) {
      const count = counts.get(`${group.id}:${module.key}`) ?? 0;

      if (count === 0) {
        missingAssignments += 1;
        complete = false;
      }

      if (count > 1) {
        duplicateAssignments += count - 1;
        complete = false;
      }
    }

    if (complete) completeGroups += 1;
  }

  return {
    completeGroups,
    missingAssignments,
    duplicateAssignments,
  };
});

const parallelViolations = computed(() => {
  const moduleByKey = new Map(
    props.modules.map((module) => [module.key, module]),
  );
  const counts = new Map<string, number>();

  for (const session of props.sessions) {
    const key = `${session.startTime}:${session.endTime}:${session.moduleKey}`;
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  let violations = 0;

  for (const [key, count] of counts) {
    const moduleKey = key.split(":").slice(2).join(":");
    const module = moduleByKey.get(moduleKey);

    if (module && count > module.maxParallel) {
      violations += 1;
    }
  }

  return violations;
});

const allComplete = computed(() =>
  props.groups.length > 0
  && coverage.value.completeGroups === props.groups.length
  && coverage.value.missingAssignments === 0
  && coverage.value.duplicateAssignments === 0
  && parallelViolations.value === 0
);
</script>

<template>
  <section class="admin-card admin-roster-validation">
    <div class="admin-roster-validation__heading">
      <div>
        <p class="admin-eyebrow">Controle</p>
        <h2>Roosterstatus</h2>
      </div>

      <span
        class="admin-status"
        :class="allComplete ? 'admin-status--confirmed' : 'admin-status--option'"
      >
        {{ allComplete ? "Structuur compleet" : "Controle nodig" }}
      </span>
    </div>

    <div class="admin-roster-validation__grid">
      <div>
        <span>Complete groepen</span>
        <strong>{{ coverage.completeGroups }}/{{ groups.length }}</strong>
      </div>
      <div>
        <span>Open moduleplekken</span>
        <strong>{{ coverage.missingAssignments }}</strong>
      </div>
      <div>
        <span>Dubbele modules</span>
        <strong>{{ coverage.duplicateAssignments }}</strong>
      </div>
      <div>
        <span>Parallelconflicten</span>
        <strong>{{ parallelViolations }}</strong>
      </div>
    </div>

    <p>
      Personeel wordt apart gecontroleerd. Een groepsrooster kan structureel compleet
      zijn terwijl er nog medewerkers moeten worden toegewezen.
    </p>
  </section>
</template>