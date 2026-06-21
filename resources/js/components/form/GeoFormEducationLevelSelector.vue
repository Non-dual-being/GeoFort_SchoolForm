<!-- src/components/form/GeoFormEducationLevelSelector.vue -->

<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { ListChecks } from "lucide-vue-next";

import type {
  AnyGroupKey,
  AnyLevelKey,
  LevelOptionForAnySector,
  SchoolLevelSelectionRule,
} from "../../types/booking/BookingProgramConfigTypes";

const props = withDefaults(
  defineProps<{
    id?: string;
    title?: string;
    subtitle?: string;
    description?: string;

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
    subtitle: "Selecteer de gewenste groepssamenstelling",
    description:
      "Selecteer eerst het onderwijsniveau. Daarna kies je per niveau de groep(en) of leerjaren die bij dit bezoek horen.",
    disabled: false,
  },
);

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

  return `Je hebt maximaal ${props.rules.maxLevels} onderwijsniveau${
    props.rules.maxLevels === 1 ? "" : "s"
  } gekozen. Vink eerst een niveau uit om deze keuze te maken.`;
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

  return `Je hebt maximaal ${props.rules.maxGroupsPerLevel} groepen of leerjaren gekozen bij dit niveau. Vink eerst een groep uit.`;
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
    <div class="education-level-selector__header">
      <div class="education-level-selector__header-top">
        <span
          class="education-level-selector__header-label"
          aria-hidden="true"
        >
          <span class="education-level-selector__header-icon">
            <ListChecks :size="15" :stroke-width="2.6" />
          </span>

          <span class="education-level-selector__eyebrow">
            {{ subtitle }}
          </span>
        </span>
      </div>

      <div class="education-level-selector__heading-row">
        <div class="education-level-selector__heading-main">
          <h3
            :id="`${id}-title`"
            class="education-level-selector__title"
          >
            {{ title }}
          </h3>

          <p class="education-level-selector__description">
            Selecteer eerst het onderwijsniveau. Daarna kies je per niveau de
            groep(en) of leerjaren die bij dit bezoek horen.
          </p>
        </div>

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
      <div
  v-if="rules && !isCollapsed"
  class="education-level-selector__rules"
>
  <div
    class="education-level-selector__rules-icon"
    aria-hidden="true"
  >
    <ListChecks :size="17" :stroke-width="2.4" />
  </div>

  <div class="education-level-selector__rules-content">
    <div class="education-level-selector__rule">
      <span class="education-level-selector__rule-label">
        Niveaus
      </span>

      <span class="education-level-selector__rule-text">
        Kies minimaal {{ rules.minLevels }} en maximaal {{ rules.maxLevels }} onderwijsniveau{{ rules.maxLevels === 1 ? "" : "s" }}.
      </span>
    </div>

    <div class="education-level-selector__rule">
      <span class="education-level-selector__rule-label">
        Groepen
      </span>

      <span class="education-level-selector__rule-text">
        Kies per niveau minimaal {{ rules.minGroupsPerLevel }} en maximaal {{ rules.maxGroupsPerLevel }} groep{{ rules.maxGroupsPerLevel === 1 ? "" : "en" }} of leerjaar{{ rules.maxGroupsPerLevel === 1 ? "" : "en" }}.
      </span>
    </div>
  </div>
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
        v-if="levelIssue"
        :key="getLevelIssueFlashKey()"
        class="education-level-selector__error education-level-selector__error--flash"
        role="alert"
      >
        {{ levelIssue }}
      </p>

      <p
        v-if="levels.length === 0"
        class="education-level-selector__empty"
      >
        Er zijn nog geen onderwijsniveaus beschikbaar. Kies eerst een geldige
        bezoekdatum en onderwijssector.
      </p>

      <div
        v-else
        class="education-level-selector__list"
      >
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
            :disabled="props.disabled"
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
                  :disabled="props.disabled"
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
    </template>
  </section>
</template>

