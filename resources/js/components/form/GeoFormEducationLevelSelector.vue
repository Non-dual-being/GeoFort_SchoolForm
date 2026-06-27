<!-- src/components/form/GeoFormEducationLevelSelector.vue -->

<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { ListChecks } from "lucide-vue-next";

import type {
  AnyGroupKey,
  AnyLevelKey,
  LevelOptionForAnySector,
  SchoolLevelSelectionRule,
  SchoolSectorKey
} from "../../types/booking/BookingProgramConfigTypes";

const props = withDefaults(
  defineProps<{
    id?: string;
    title?: string;
    description?: string;

    sector: SchoolSectorKey | null;
    levels: LevelOptionForAnySector[];
    selectedLevels: AnyLevelKey[];
    selectedGroupsByLevel: Record<string, string[]>;
    rules: SchoolLevelSelectionRule | null;

    levelIssue: string | null;
    groupIssues: Record<string, string>;

    levelFlashTrigger: number;
    groupFlashTriggers: Record<string, number>;

    disabled?: boolean;
  }>(),
  {
    id: "education-level-selector",
    title: "Groepsselectie",
    description:
      "Selecteer eerst het onderwijsniveau. Daarna kies je per niveau de groep(en) of leerjaren die bij dit bezoek horen.",
    disabled: false,
  },
);

function getSectorLabel(sector: SchoolSectorKey | null): string {
  if (sector === "primairOnderwijs") {
    return "primair onderwijs";
  }

  if (sector === "voortgezetOnderbouw") {
    return "voortgezet onderwijs onderbouw";
  }

  if (sector === "voortgezetBovenbouw") {
    return "voortgezet onderwijs bovenbouw";
  }

  return "de gekozen onderwijssector";
}

function getRangeText(
  min: number,
  max: number,
  singular: string,
  plural: string,
): string {
  const noun = max === 1 ? singular : plural;

  if (min === max) {
    return `${max} ${noun}`;
  }

  return `${min}–${max} ${noun}`;
}

function getLevelRuleText(rules: SchoolLevelSelectionRule): string {
  return getRangeText(
    rules.minLevels,
    rules.maxLevels,
    "niveau",
    "niveaus",
  );
}

function getGroupRuleText(rules: SchoolLevelSelectionRule): string {
  return getRangeText(
    rules.minGroupsPerLevel,
    rules.maxGroupsPerLevel,
    "groep per niveau",
    "groepen per niveau",
  );
}


const emit = defineEmits<{
  "update:selectedLevels": [value: AnyLevelKey[]];
  "update:selectedGroupsByLevel": [value: Record<string, string[]>];
}>();

const rootRef = ref<HTMLElement | null>(null);
const isCollapsed = ref(false);

const hasAnyIssue = computed(() => {
  return props.levelIssue !== null || Object.keys(props.groupIssues).length > 0;
});

const selectedLevelSet = computed(() => {
  return new Set<AnyLevelKey>(props.selectedLevels);
});

const hasSelectedLevels = computed(() => {
  return props.selectedLevels.length > 0;
});

const hasSelectedGroups = computed(() => {
  return Object.values(props.selectedGroupsByLevel).some((groups) => groups.length > 0);
})

const hasValidatedLevels = computed(() => {
  return props.levelFlashTrigger > 0 || props.selectedLevels.length > 0;
})

const hasValidatedGroups = computed(() => {
  return (
    hasSelectedGroups.value ||
    Object.keys(props.groupIssues).length > 0 ||
    props.levelFlashTrigger > 0
  );
});


type SelectionBadgeState = "idle" | "valid" | "invalid";

const levelBadgeState = computed<SelectionBadgeState>(() => {
  if (!hasValidatedLevels.value) {
    return "idle";
  }

  return props.levelIssue ? "invalid" : "valid";
});

const groupBadgeState = computed<SelectionBadgeState>(() => {
  /**
   * Zolang er nog geen niveau is gekozen en er nog niets gevalideerd is,
   * blijft deze badge neutraal.
   */
  if (!hasValidatedGroups.value || props.selectedLevels.length === 0) {
    return "idle";
  }

  return Object.keys(props.groupIssues).length === 0 ? "valid" : "invalid";
});

function getLevelSelectionLabel(): string {
  if (props.sector === "primairOnderwijs") {
    return "Regulier of speciaal";
  }

  if (!props.rules) {
    return "Niveaukeuze";
  }

  return getLevelRuleText(props.rules);
}

function getGroupSelectionLabel(): string {
  if (!props.rules) {
    return "Groepskeuze";
  }

  return getGroupRuleText(props.rules);
}


const canCollapse = computed(() => {
  return !props.disabled && hasSelectedLevels.value && !hasAnyIssue.value;
});

const maxLevelsReached = computed(() => {
  if (!props.rules) {
    return false;
  }

  return props.selectedLevels.length >= props.rules.maxLevels;
});

function isLevelMaxDisabled(levelKey: AnyLevelKey): boolean {
  if (props.disabled) {
    return false;
  }

  if (isLevelSelected(levelKey)) {
    return false;
  }

  return maxLevelsReached.value;
}


function isGroupMaxDisabled(levelKey: AnyLevelKey, groupKey: AnyGroupKey): boolean {
  if (props.disabled || !isLevelSelected(levelKey)) {
    return false;
  }

  if (isGroupSelected(levelKey, groupKey)) {
    return false;
  }

  return isGroupMaxReached(levelKey);
}



function isLevelSelected(levelKey: AnyLevelKey): boolean {
  return selectedLevelSet.value.has(levelKey);
}

function isLevelDisabled(levelKey: AnyLevelKey): boolean {
  if (props.disabled) {
    return true;
  }

  if (isLevelSelected(levelKey)) {
    return false;
  }

  return maxLevelsReached.value;
}

function isGroupSelected(levelKey: AnyLevelKey, groupKey: AnyGroupKey): boolean {
  return props.selectedGroupsByLevel[levelKey]?.includes(groupKey) ?? false;
}

function isGroupMaxReached(levelKey: AnyLevelKey): boolean {
  if (!props.rules) {
    return false;
  }

  const currentGroups = props.selectedGroupsByLevel[levelKey] ?? [];

  return currentGroups.length >= props.rules.maxGroupsPerLevel;
}

function isGroupDisabled(levelKey: AnyLevelKey, groupKey: AnyGroupKey): boolean {
  if (props.disabled || !isLevelSelected(levelKey)) {
    return true;
  }

  if (isGroupSelected(levelKey, groupKey)) {
    return false;
  }

  return isGroupMaxReached(levelKey);
}


function getLevelDisabledReason(levelKey: AnyLevelKey): string | null {
  if (!isLevelMaxDisabled(levelKey)) {
    return null;
  }

  if (!props.rules) {
    return null;
  }

  if (props.rules.maxLevels === 1) {
    return "Er kan maximaal 1 niveau worden gekozen.";
  }

  return `Er kunnen maximaal ${props.rules.maxLevels} niveaus worden gekozen.`;
}


function getGroupDisabledReason(
  levelKey: AnyLevelKey,
  groupKey: AnyGroupKey,
): string | null {
  if (!isGroupMaxDisabled(levelKey, groupKey)) {
    return null;
  }

  if (!props.rules) {
    return null;
  }

  if (props.rules.maxGroupsPerLevel === 1) {
    return "Selecteer max 1 groep";
  }

  return `Maximum van ${props.rules.maxGroupsPerLevel} groepen bereikt`;
}




function cloneGroupsByLevel(): Record<string, string[]> {
  return Object.fromEntries(
    Object.entries(props.selectedGroupsByLevel).map(([levelKey, groups]) => [
      levelKey,
      [...groups],
    ]),
  );
}

function toggleLevel(levelKey: AnyLevelKey): void {
  if (isLevelDisabled(levelKey)) {
    return;
  }

  const alreadySelected = isLevelSelected(levelKey);

  const nextSelectedLevels = alreadySelected
    ? props.selectedLevels.filter((key) => key !== levelKey)
    : [...props.selectedLevels, levelKey];

  const nextGroupsByLevel = cloneGroupsByLevel();

  if (alreadySelected) {
    nextGroupsByLevel[levelKey] = [];
  } else {
    nextGroupsByLevel[levelKey] ??= [];
  }

  isCollapsed.value = false;

  emit("update:selectedLevels", nextSelectedLevels);
  emit("update:selectedGroupsByLevel", nextGroupsByLevel);
}

function toggleGroup(levelKey: AnyLevelKey, groupKey: AnyGroupKey): void {
  if (isGroupDisabled(levelKey, groupKey)) {
    return;
  }

  const currentGroups = props.selectedGroupsByLevel[levelKey] ?? [];
  const alreadySelected = currentGroups.includes(groupKey);

  const nextGroups = alreadySelected
    ? currentGroups.filter((key) => key !== groupKey)
    : [...currentGroups, groupKey];

  isCollapsed.value = false;

  emit("update:selectedGroupsByLevel", {
    ...cloneGroupsByLevel(),
    [levelKey]: nextGroups,
  });
}

function collapseSelection(): void {
  if (!canCollapse.value) {
    return;
  }

  isCollapsed.value = true;
}

function expandSelection(): void {
  isCollapsed.value = false;
}

function getLevelOption(levelKey: AnyLevelKey): LevelOptionForAnySector | null {
  return props.levels.find((level) => level.key === levelKey) ?? null;
}

function getLevelLabel(levelKey: AnyLevelKey): string {
  return getLevelOption(levelKey)?.label ?? levelKey;
}

function getGroupLabel(levelKey: AnyLevelKey, groupKey: string): string {
  const level = getLevelOption(levelKey);

  return level?.groups.find((group) => group.key === groupKey)?.label ?? groupKey;
}

function getLevelGroupCountText(level: LevelOptionForAnySector): string {
  const count = level.groups.length;

  if (count === 1) {
    return "1 groep/leerjaar beschikbaar";
  }

  return `${count} groepen/leerjaren beschikbaar`;
}

function getSelectedGroupCountText(levelKey: AnyLevelKey): string {
  const count = props.selectedGroupsByLevel[levelKey]?.length ?? 0;

  if (count === 0) {
    return "Nog geen groep gekozen";
  }

  if (count === 1) {
    return "1 groep gekozen";
  }

  return `${count} groepen gekozen`;
}

function getNoteNiveauLabel(rules: SchoolLevelSelectionRule): string {
  return rules.maxLevels === 1 ? "Niveau" : "Niveaus";
}


const summaryItems = computed(() => {
  return props.selectedLevels.map((levelKey) => {
    const groups = props.selectedGroupsByLevel[levelKey] ?? [];

    return {
      levelKey,
      levelLabel: getLevelLabel(levelKey),
      groupLabels: groups.map((groupKey) => getGroupLabel(levelKey, groupKey)),
    };
  });
});

const summaryText = computed(() => {
  if (summaryItems.value.length === 0) {
    return "Nog geen groepssamenstelling gekozen.";
  }

  return summaryItems.value
    .map((item) => {
      const groups =
        item.groupLabels.length > 0
          ? item.groupLabels.join(", ")
          : "geen groep gekozen";

      return `${item.levelLabel}: ${groups}`;
    })
    .join(" · ");
});

function getGroupIssueFlashKey(levelKey: AnyLevelKey): string {
  return `group-issue-${levelKey}-${props.groupFlashTriggers[levelKey] ?? 0}`;
}

function getLevelIssueFlashKey(): string {
  return `level-issue-${props.levelFlashTrigger}`;
}


async function focus(): Promise<void> {
  await nextTick();

  const firstButton = rootRef.value?.querySelector<HTMLButtonElement>(
    ".education-level-card__button",
  );

  firstButton?.focus();
}

async function scrollIntoView(): Promise<void> {
  await nextTick();

  rootRef.value?.scrollIntoView({
    behavior: "smooth",
    block: "center",
  });
}

watch(canCollapse, (allowedToCollapse) => {
  if (!allowedToCollapse) {
    isCollapsed.value = false;
  }
});

defineExpose({
  focus,
  scrollIntoView,
});
</script>


<template>
  <section
    :id="id"
    ref="rootRef"
    class="education-level-selector"
    :class="{
      'education-level-selector--disabled': disabled,
      'education-level-selector--has-issue': hasAnyIssue,
      'education-level-selector--collapsed': isCollapsed,
    }"
    :aria-labelledby="`${id}-title`"
  >
    <div class="education-level-selector__intro">
      <div class="education-level-selector__intro-main">
        <h3
          :id="`${id}-title`"
          class="education-level-selector__title"
        >
          <span
            class="education-level-selector__title-icon"
            aria-hidden="true"
          >
            <ListChecks :size="17" :stroke-width="2.7" />
          </span>

          <span>
            {{ title }}
          </span>
        </h3>

        <p class="education-level-selector__description">
          Selecteer eerst het onderwijsniveau voor
          <strong class="education-level-selector__text-accent">
            {{ getSectorLabel(sector) }}
          </strong>.
          Daarna kies je per gekozen niveau de groep(en) of leerjaren die bij
          dit bezoek horen.
        </p>

        <div
          v-if="rules"
          class="education-level-selector__rule-strip"
          aria-label="Selectieregels voor onderwijsniveau en groepen"
        >
          <span class="education-level-selector__rule-chip">
            <span class="education-level-selector__rule-chip-label">
              Sector
            </span>

            <strong class="education-level-selector__rule-chip-value">
              {{ getSectorLabel(sector) }}
            </strong>
          </span>

          <span class="education-level-selector__rule-chip">
            <span class="education-level-selector__rule-chip-label">
              Niveaus
            </span>

            <strong class="education-level-selector__rule-chip-value">
              {{ getLevelRuleText(rules) }}
            </strong>
          </span>

          <span class="education-level-selector__rule-chip">
            <span class="education-level-selector__rule-chip-label">
              Groepen
            </span>

            <strong class="education-level-selector__rule-chip-value">
              {{ getGroupRuleText(rules) }}
            </strong>
          </span>
        </div>
      </div>

      <div class="education-level-selector__actions">
        <button
          v-if="canCollapse && !isCollapsed"
          type="button"
          class="education-level-selector__collapse-button"
          @click="collapseSelection"
        >
          Samenvatten
        </button>

        <button
          v-if="isCollapsed"
          type="button"
          class="education-level-selector__collapse-button"
          @click="expandSelection"
        >
          Aanpassen
        </button>
      </div>
    </div>

    <div
      v-if="isCollapsed"
      class="education-level-summary"
    >
      <div class="education-level-summary__badge">
        ✓ Compleet
      </div>

      <p class="education-level-summary__text">
        {{ summaryText }}
      </p>
    </div>

    <template v-else>
      <p
        v-if="levels.length === 0"
        class="education-level-selector__empty"
      >
        Er zijn nog geen onderwijsniveaus beschikbaar. Kies eerst een geldige
        bezoekdatum en onderwijssector.
      </p>

      <div
        v-else
        class="education-level-selector__wizard"
      >
        <div
          class="education-level-selector__selection-status"
          aria-label="Status van de groepsselectie"
        >
          <span
            class="education-level-selector__selection-badge"
            :class="{
              'education-level-selector__selection-badge--idle': levelBadgeState === 'idle',
              'education-level-selector__selection-badge--valid': levelBadgeState === 'valid',
              'education-level-selector__selection-badge--invalid': levelBadgeState === 'invalid',
            }"
          >
            <span
              class="education-level-selector__selection-badge-icon"
              aria-hidden="true"
            >
              {{ levelBadgeState === "valid" ? "✓" : "" }}
            </span>

            <span class="education-level-selector__selection-badge-text">
              {{ getLevelSelectionLabel() }}
            </span>
          </span>

          <span
            class="education-level-selector__selection-badge"
            :class="{
              'education-level-selector__selection-badge--idle': groupBadgeState === 'idle',
              'education-level-selector__selection-badge--valid': groupBadgeState === 'valid',
              'education-level-selector__selection-badge--invalid': groupBadgeState === 'invalid',
            }"
          >
            <span
              class="education-level-selector__selection-badge-icon"
              aria-hidden="true"
            >
              {{ groupBadgeState === "valid" ? "✓" : "" }}
            </span>

            <span class="education-level-selector__selection-badge-text">
              {{ getGroupSelectionLabel() }}
            </span>
          </span>
        </div>

        <p
          v-if="levelIssue"
          :key="getLevelIssueFlashKey()"
          class="education-level-selector__error education-level-selector__error--flash"
          role="alert"
        >
          {{ levelIssue }}
        </p>

        <div class="education-level-selector__list">
          <article
            v-for="level in levels"
            :key="level.key"
            class="education-level-card"
            :class="{
              'education-level-card--selected': isLevelSelected(level.key),
              'education-level-card--disabled': isLevelDisabled(level.key),
              'education-level-card--max-disabled': isLevelMaxDisabled(level.key),
              'education-level-card--has-error': Boolean(groupIssues[level.key]),
            }"
            :data-disabled-reason="getLevelDisabledReason(level.key)"
          >
            <button
              type="button"
              class="education-level-card__button"
              :aria-pressed="isLevelSelected(level.key)"
              :aria-expanded="isLevelSelected(level.key)"
              :aria-controls="`${id}-${level.key}-groups`"
              :disabled="disabled"
              :aria-disabled="isLevelDisabled(level.key)"
              @click="toggleLevel(level.key)"
            >
              <span
                class="education-level-card__indicator"
                aria-hidden="true"
              >
                {{ isLevelSelected(level.key) ? "✓" : "+" }}
              </span>

              <span class="education-level-card__main">
                <span class="education-level-card__label">
                  {{ level.label }}
                </span>

                <span class="education-level-card__meta">
                  {{ getLevelGroupCountText(level) }}
                </span>
              </span>

              <span class="education-level-card__status">
                {{
                  isLevelSelected(level.key)
                    ? getSelectedGroupCountText(level.key)
                    : maxLevelsReached
                      ? "Maximum bereikt"
                      : "Niet gekozen"
                }}
              </span>
            </button>

            <Transition name="education-groups-reveal">
              <div
                v-if="isLevelSelected(level.key)"
                :id="`${id}-${level.key}-groups`"
                class="education-level-card__groups"
              >
                <div class="education-level-card__groups-header">
                  <span class="education-level-card__groups-title">
                    Kies de groep(en) of leerjaren voor {{ level.label }}
                  </span>
                </div>
                <p
                  v-if="groupIssues[level.key]"
                  :key="getGroupIssueFlashKey(level.key)"
                  class="education-level-card__error education-level-card__error--flash"
                  role="alert"
                >
                  {{ groupIssues[level.key] }}
                </p>

                <div
                  class="education-group-list"
                  role="group"
                  :aria-label="`Groepen of leerjaren voor ${level.label}`"
                >
                  <button
                    v-for="group in level.groups"
                    :key="group.key"
                    type="button"
                    class="education-group-chip"
                    :class="{
                      'education-group-chip--selected': isGroupSelected(level.key, group.key),
                      'education-group-chip--disabled': isGroupDisabled(level.key, group.key),
                      'education-group-chip--max-disabled': isGroupMaxDisabled(level.key, group.key),
                    }"
                    :aria-pressed="isGroupSelected(level.key, group.key)"
                    :aria-disabled="isGroupDisabled(level.key, group.key)"
                    :disabled="disabled"
                    :data-disabled-reason="getGroupDisabledReason(level.key, group.key)"
                    @click="toggleGroup(level.key, group.key)"
                  >
                    <span
                      class="education-group-chip__check"
                      aria-hidden="true"
                    >
                      {{ isGroupSelected(level.key, group.key) ? "✓" : "" }}
                    </span>

                    <span class="education-group-chip__label">
                      {{ group.label }}
                    </span>
                  </button>
                </div>
              </div>
            </Transition>
          </article>
        </div>
      </div>
    </template>
  </section>
</template>