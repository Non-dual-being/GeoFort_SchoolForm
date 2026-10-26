<script setup lang="ts">
import { computed, ref, useId } from "vue";
import type { RosterStaffPreference } from "../../types/roster";

const props = defineProps<{ preferences: RosterStaffPreference[]; canCook: boolean }>();
const expanded = ref(false);
const skillsId = useId();
const skills = computed(() => {
  const ranked = [...props.preferences].sort((left, right) => left.rank - right.rank).map(preference => ({
    key: preference.moduleKey,
    rank: String(preference.rank),
    label: preference.moduleKey === "Voedsel-Innovatie" ? "Voedsel Innovatie · begeleider" : preference.moduleLabel,
  }));
  // Cooking is a separate capability without a preference rank; keep the ranked top three first.
  if (props.canCook) ranked.push({ key: "food-cook", rank: "Kok", label: "Voedsel Innovatie · kok" });
  return ranked;
});
const visibleSkills = computed(() => expanded.value ? skills.value : skills.value.slice(0, 3));
</script>

<template>
  <div class="admin-roster-person__modules">
    <div :id="skillsId" class="admin-roster-person__skills" aria-label="Modulevaardigheden op voorkeursvolgorde">
      <span v-for="skill in visibleSkills" :key="skill.key">
        <b>{{ skill.rank }}</b>
        <span class="admin-roster-skill-name" tabindex="0">{{ skill.label }}</span>
      </span>
    </div>
    <button v-if="skills.length > 3" type="button" class="admin-roster-person__more"
      :aria-expanded="expanded" :aria-controls="skillsId" @click="expanded = !expanded">
      {{ expanded ? "Bekijk minder" : `Bekijk meer (${skills.length - 3})` }}
    </button>
  </div>
</template>
