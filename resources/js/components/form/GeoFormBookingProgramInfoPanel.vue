<script setup lang="ts">
import { computed, ref } from "vue";
import { ChevronDown } from "lucide-vue-next";

import type {
  BookingProgramConfigData,
  ModuleGroupConfig,
  PriceType,
  ProgramConfig,
  ProgramKey,
  SchoolSectorKey,
  Weekday,
} from "./../../types/booking/BookingProgramConfigTypes";

import {
  priceTypeLabels,
  programOrder,
  schoolSectorOrder,
  weekdayLabels,
} from "./../../config/booking/BookingFields";

type SchoolCategory = "PO" | "VO";
type SchoolCategoryLabel = SchoolCategory | "PO & VO";

type InfoSectionId =
  | "programs"
  | "modules"
  | "included"
  | "special-notes";

type ModuleOverviewItem = {
  id: string;
  schoolType: SchoolSectorKey;
  schoolTypeLabel: string;
  program: ProgramKey;
  programLabel: string;
  standaard: string[];
  keuze: string[];
};

const props = defineProps<{
  config: BookingProgramConfigData;
  schoolType?: SchoolSectorKey;
  programDuration?: ProgramKey;
}>();

const allSectionIds: InfoSectionId[] = [
  "programs",
  "modules",
  "included",
  "special-notes",
];

const openSectionIds = ref<Set<InfoSectionId>>(new Set());

const schoolSectorByKey = computed(() => {
  return Object.fromEntries(
    props.config.schoolTypes.map((schoolType) => [
      schoolType.value,
      schoolType,
    ]),
  ) as Record<
    SchoolSectorKey,
    (typeof props.config.schoolTypes)[number]
  >;
});

const programEntries = computed(() => {
  return programOrder.map((key) => ({
    key,
    ...props.config.programs[key],
  }));
});

const moduleOverviewItems = computed<ModuleOverviewItem[]>(() => {
  const items: ModuleOverviewItem[] = [];

  for (const schoolType of schoolSectorOrder) {
    for (const program of programOrder) {
      const selection = getModuleSelection(schoolType, program);

      if (!selection) {
        continue;
      }

      items.push({
        id: `${schoolType}-${program}`,
        schoolType,
        schoolTypeLabel: schoolSectorByKey.value[schoolType].label,
        program,
        programLabel: props.config.programs[program].label,
        standaard: selection.standaard,
        keuze: selection.keuze,
      });
    }
  }

  return items;
});

const areAllSectionsOpen = computed(() => {
  return allSectionIds.every((id) => openSectionIds.value.has(id));
});

function getModuleSelection(
  schoolType: SchoolSectorKey,
  program: ProgramKey,
): ModuleGroupConfig | null {
  const schoolModules = props.config.modules[schoolType] as Partial<
    Record<ProgramKey, ModuleGroupConfig>
  >;

  return schoolModules[program] ?? null;
}

function getSchoolCategory(
  schoolType: SchoolSectorKey,
): SchoolCategory {
  if (schoolType === "primairOnderwijs") {
    return "PO";
  }

  return "VO";
}

function getSchoolCategoryLabel(
  allowedSchoolTypes: SchoolSectorKey[],
): SchoolCategoryLabel {
  const categories = allowedSchoolTypes.map(getSchoolCategory);
  const uniqueCategories = [...new Set(categories)];

  if (uniqueCategories.length > 1) {
    return "PO & VO";
  }

  return uniqueCategories[0] ?? "VO";
}

function formatCurrency(price: number): string {
  if (price === 0) {
    return "Gratis";
  }

  return new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency: "EUR",
  }).format(price);
}

function formatWeekdays(weekdays: Weekday[]): string {
  const isFullSchoolWeek =
    weekdays.length === 5 &&
    weekdays.includes(1) &&
    weekdays.includes(2) &&
    weekdays.includes(3) &&
    weekdays.includes(4) &&
    weekdays.includes(5);

  if (isFullSchoolWeek) {
    return "maandag t/m vrijdag";
  }

  return weekdays
    .map((weekday) => weekdayLabels[weekday])
    .join(", ");
}

function getVisitPriceEntries(
  program: ProgramKey,
): [PriceType, number][] {
  const prices = props.config.prices.bezoek[program] as Partial<
    Record<PriceType, number>
  >;

  return Object.entries(prices) as [PriceType, number][];
}

function getMinimumStudents(
  program: ProgramKey,
  priceType: PriceType,
): number | null {
  const minimums = props.config.studentLimits.min[program] as Partial<
    Record<PriceType, number>
  >;

  return minimums[priceType] ?? null;
}

function getMaximumStudents(program: ProgramKey): number {
  return props.config.studentLimits.max[program];
}

function getAllowedSchoolTypeLabels(
  program: ProgramConfig,
): string[] {
  return program.allowedSchoolTypes.map((schoolType) => {
    return schoolSectorByKey.value[schoolType].label;
  });
}

function sectionButtonId(id: InfoSectionId): string {
  return `booking-info-${id}-toggle`;
}

function sectionPanelId(id: InfoSectionId): string {
  return `booking-info-${id}-panel`;
}

function sectionHeadingId(id: InfoSectionId): string {
  return `booking-info-${id}-heading`;
}

function isSectionOpen(id: InfoSectionId): boolean {
  return openSectionIds.value.has(id);
}

function toggleSection(id: InfoSectionId): void {
  const nextOpenSectionIds = new Set(openSectionIds.value);

  if (nextOpenSectionIds.has(id)) {
    nextOpenSectionIds.delete(id);
  } else {
    nextOpenSectionIds.add(id);
  }

  openSectionIds.value = nextOpenSectionIds;
}

function toggleAllSections(): void {
  openSectionIds.value = areAllSectionsOpen.value
    ? new Set()
    : new Set(allSectionIds);
}
</script>

<template>
  <section
    class="booking-info-card"
    aria-label="Praktische informatie"
  >
    <div class="booking-info-card__controls">
      <button
        class="booking-info-card__toggle-all"
        type="button"
        @click="toggleAllSections"
      >
        <span>
          {{
            areAllSectionsOpen
              ? "Alles inklappen"
              : "Alles uitklappen"
          }}
        </span>

        <ChevronDown
          class="booking-info-card__toggle-all-icon"
          :class="{ 'is-open': areAllSectionsOpen }"
          :size="19"
          aria-hidden="true"
        />
      </button>
    </div>

    <div class="booking-info-card__grid">
      <!-- Programma-aanbod -->
      <article
        class="booking-info-section-card"
        :class="{ 'is-open': isSectionOpen('programs') }"
      >
        <header class="booking-info-section-card__header">
          <div class="booking-info-section-card__intro">
            <h3
              :id="sectionHeadingId('programs')"
              class="booking-info-section-card__title"
            >
              Programma-aanbod
            </h3>

            <p class="booking-info-section-card__summary">
              Dag- en ochtendprogramma
            </p>
          </div>

          <button
            :id="sectionButtonId('programs')"
            class="booking-info-section-card__action"
            type="button"
            :aria-label="`${isSectionOpen('programs') ? 'Sluiten' : 'Bekijken'}: Programma-aanbod`"
            :aria-expanded="isSectionOpen('programs')"
            :aria-controls="sectionPanelId('programs')"
            @click="toggleSection('programs')"
          >
            <span>
              {{
                isSectionOpen("programs")
                  ? "Sluiten"
                  : "Bekijken"
              }}
            </span>

            <ChevronDown
              class="booking-info-section-card__chevron"
              :size="20"
              aria-hidden="true"
            />
          </button>
        </header>

        <div
          v-show="isSectionOpen('programs')"
          :id="sectionPanelId('programs')"
          class="booking-info-section-card__details"
          role="region"
          :aria-labelledby="sectionHeadingId('programs')"
        >
          <div class="booking-info-program-grid">
            <article
              v-for="program in programEntries"
              :key="program.key"
              class="booking-info-program"
            >
              <header class="booking-info-program__header">
                <div>
                  <h4 class="booking-info-program__title">
                    {{ program.label }}
                  </h4>

                  <p class="booking-info-program__category">
                    {{
                      getSchoolCategoryLabel(
                        program.allowedSchoolTypes,
                      )
                    }}
                  </p>
                </div>

                <span class="booking-info-program__days">
                  {{ formatWeekdays(program.allowedWeekdays) }}
                </span>
              </header>

              <ul class="booking-info-list booking-info-list--compact">
                <li
                  v-for="line in program.description"
                  :key="line"
                >
                  {{ line }}
                </li>
              </ul>

              <div class="booking-info-subblock">
                <p class="booking-info-subtitle">
                  Beschikbaar voor
                </p>

                <ul class="booking-pill-list">
                  <li
                    v-for="schoolTypeLabel in getAllowedSchoolTypeLabels(
                      program,
                    )"
                    :key="schoolTypeLabel"
                  >
                    {{ schoolTypeLabel }}
                  </li>
                </ul>
              </div>

              <dl class="booking-compact-facts">
                <div>
                  <dt>Dagen</dt>
                  <dd>
                    {{ formatWeekdays(program.allowedWeekdays) }}
                  </dd>
                </div>

                <div>
                  <dt>Kosten</dt>
                  <dd>
                    <span
                      v-for="[priceType, price] in getVisitPriceEntries(
                        program.key,
                      )"
                      :key="priceType"
                    >
                      {{ priceTypeLabels[priceType] }}:
                      <strong>{{ formatCurrency(price) }}</strong>
                    </span>
                  </dd>
                </div>

                <div>
                  <dt>Leerlingen</dt>
                  <dd>
                    <span
                      v-for="[priceType] in getVisitPriceEntries(
                        program.key,
                      )"
                      :key="`${program.key}-${priceType}-students`"
                    >
                      {{ priceTypeLabels[priceType] }}:
                      min.
                      <strong>
                        {{
                          getMinimumStudents(
                            program.key,
                            priceType,
                          )
                        }}
                      </strong>,
                      max.
                      <strong>
                        {{ getMaximumStudents(program.key) }}
                      </strong>
                    </span>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </div>
      </article>

      <!-- Modules -->
      <article
        class="booking-info-section-card"
        :class="{ 'is-open': isSectionOpen('modules') }"
      >
        <header class="booking-info-section-card__header">
          <div class="booking-info-section-card__intro">
            <h3
              :id="sectionHeadingId('modules')"
              class="booking-info-section-card__title"
            >
              Modules per programma
            </h3>

            <p class="booking-info-section-card__summary">
              Standaard- en keuzemodules
            </p>
          </div>

          <button
            :id="sectionButtonId('modules')"
            class="booking-info-section-card__action"
            type="button"
            :aria-label="`${isSectionOpen('modules') ? 'Sluiten' : 'Bekijken'}: Modules per programma`"
            :aria-expanded="isSectionOpen('modules')"
            :aria-controls="sectionPanelId('modules')"
            @click="toggleSection('modules')"
          >
            <span>
              {{
                isSectionOpen("modules")
                  ? "Sluiten"
                  : "Bekijken"
              }}
            </span>

            <ChevronDown
              class="booking-info-section-card__chevron"
              :size="20"
              aria-hidden="true"
            />
          </button>
        </header>

        <div
          v-show="isSectionOpen('modules')"
          :id="sectionPanelId('modules')"
          class="booking-info-section-card__details"
          role="region"
          :aria-labelledby="sectionHeadingId('modules')"
        >
          <div class="booking-info-module-grid">
            <article
              v-for="item in moduleOverviewItems"
              :key="item.id"
              class="booking-info-module"
            >
              <header class="booking-info-module__header">
                <div>
                  <h4 class="booking-info-module__title">
                    {{ item.schoolTypeLabel }}
                  </h4>

                  <p class="booking-info-module__program">
                    {{ item.programLabel }}
                  </p>
                </div>

                <span class="booking-info-module__count">
                  {{ item.standaard.length }} standaard
                  <template v-if="item.keuze.length > 0">
                    + {{ item.keuze.length }} keuze
                  </template>
                </span>
              </header>

              <p class="booking-info-subtitle">
                Standaard inbegrepen
              </p>

              <ul
                class="booking-pill-list booking-pill-list--compact booking-pill-list--standard"
              >
                <li
                  v-for="moduleName in item.standaard"
                  :key="moduleName"
                >
                  {{ moduleName }}
                </li>
              </ul>

              <template v-if="item.keuze.length > 0">
                <p class="booking-info-subtitle">
                  Keuzemodules
                </p>

                <ul
                  class="booking-pill-list booking-pill-list--compact booking-pill-list--choice"
                >
                  <li
                    v-for="moduleName in item.keuze"
                    :key="moduleName"
                  >
                    {{ moduleName }}
                  </li>
                </ul>
              </template>
            </article>
          </div>
        </div>
      </article>

      <!-- Inbegrepen -->
      <article
        class="booking-info-section-card booking-info-section-card--included"
        :class="{ 'is-open': isSectionOpen('included') }"
      >
        <header class="booking-info-section-card__header">
          <div class="booking-info-section-card__intro">
            <h3
              :id="sectionHeadingId('included')"
              class="booking-info-section-card__title"
            >
              Inbegrepen bij de onderwijsdag
            </h3>

            <p class="booking-info-section-card__summary">
              Vegetarische snack · gratis begeleider · koffie/thee
            </p>
          </div>

          <button
            :id="sectionButtonId('included')"
            class="booking-info-section-card__action"
            type="button"
            :aria-label="`${isSectionOpen('included') ? 'Sluiten' : 'Bekijken'}: Inbegrepen bij de onderwijsdag`"
            :aria-expanded="isSectionOpen('included')"
            :aria-controls="sectionPanelId('included')"
            @click="toggleSection('included')"
          >
            <span>
              {{
                isSectionOpen("included")
                  ? "Sluiten"
                  : "Bekijken"
              }}
            </span>

            <ChevronDown
              class="booking-info-section-card__chevron"
              :size="20"
              aria-hidden="true"
            />
          </button>
        </header>

        <div
          v-show="isSectionOpen('included')"
          :id="sectionPanelId('included')"
          class="booking-info-section-card__details"
          role="region"
          :aria-labelledby="sectionHeadingId('included')"
        >
          <ul class="booking-info-list booking-info-list--spacious">
            <li
              v-for="includedItem in config.practicalInfo.included"
              :key="includedItem"
            >
              {{ includedItem }}
            </li>
          </ul>
        </div>
      </article>

      <!-- Bijzonderheden -->
      <article
        class="booking-info-section-card"
        :class="{ 'is-open': isSectionOpen('special-notes') }"
      >
        <header class="booking-info-section-card__header">
          <div class="booking-info-section-card__intro">
            <h3
              :id="sectionHeadingId('special-notes')"
              class="booking-info-section-card__title"
            >
              Bijzonderheden
            </h3>

            <p class="booking-info-section-card__summary">
              Cultuurkaart · allergieën · Museumjaarkaart
            </p>
          </div>

          <button
            :id="sectionButtonId('special-notes')"
            class="booking-info-section-card__action"
            type="button"
            :aria-label="`${isSectionOpen('special-notes') ? 'Sluiten' : 'Bekijken'}: Bijzonderheden`"
            :aria-expanded="isSectionOpen('special-notes')"
            :aria-controls="sectionPanelId('special-notes')"
            @click="toggleSection('special-notes')"
          >
            <span>
              {{
                isSectionOpen("special-notes")
                  ? "Sluiten"
                  : "Bekijken"
              }}
            </span>

            <ChevronDown
              class="booking-info-section-card__chevron"
              :size="20"
              aria-hidden="true"
            />
          </button>
        </header>

        <div
          v-show="isSectionOpen('special-notes')"
          :id="sectionPanelId('special-notes')"
          class="booking-info-section-card__details"
          role="region"
          :aria-labelledby="sectionHeadingId('special-notes')"
        >
          <ul class="booking-info-list booking-info-list--spacious">
            <li
              v-for="note in config.practicalInfo.specialNotes"
              :key="note"
            >
              {{ note }}
            </li>
          </ul>

          <p class="booking-info-card__note">
            {{ config.practicalInfo.vatText }}
          </p>
        </div>
      </article>
    </div>
  </section>
</template>
