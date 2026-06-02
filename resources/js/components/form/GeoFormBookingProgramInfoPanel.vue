<script setup lang="ts">
import { computed } from "vue";

import type {
  BookingProgramConfigData,
  ModuleSelection,
  PriceType,
  ProgramConfig,
  ProgramKey,
  SchoolTypeKey,
  Weekday,
  Category,
  CategoryLabel
} from "./../../types/booking/BookingProgramConfigTypes";

import {
  schoolTypeOrder,
  programOrder,
  priceTypeLabels,
  weekdayLabels,
} from "./../../config/booking/BookingFields";

type SchoolCategoryLabel = CategoryLabel | "PO & VO";

const props = defineProps<{
  config: BookingProgramConfigData;
  schoolType?: SchoolTypeKey;
  programDuration?: ProgramKey;
}>();

type ModuleOverviewItem = {
  id: string;
  schoolType: SchoolTypeKey;
  schoolTypeLabel: string;
  program: ProgramKey;
  programLabel: string;
  standaard: string[];
  keuze: string[];
};

const programEntries = computed(() => {
  return programOrder.map((key) => {
    return {
      key,
      ...props.config.programs[key],
    };
  });
});

const moduleOverviewItems = computed<ModuleOverviewItem[]>(() => {
  const items: ModuleOverviewItem[] = [];

  for (const schoolType of schoolTypeOrder) {
    for (const program of programOrder) {
      const selection = getModuleSelection(schoolType, program);

      if (!selection) {
        continue;
      }

      items.push({
        id: `${schoolType}-${program}`,
        schoolType,
        schoolTypeLabel: props.config.schoolTypes[schoolType].label,
        program,
        programLabel: props.config.programs[program].label,
        standaard: selection.standaard,
        keuze: selection.keuze,
      });
    }
  }

  return items;
});

function getModuleSelection(
  schoolType: SchoolTypeKey,
  program: ProgramKey,
): ModuleSelection | null {
  const schoolModules = props.config.modules[schoolType] as Partial<
    Record<ProgramKey, ModuleSelection>
  >;

  return schoolModules[program] ?? null;
}

function getSchoolCategory(
  schoolType: SchoolTypeKey
): CategoryLabel {
  if (schoolType === "primairOnderwijs")
    return "PO"

  return "VO"
}

function getSchoolCategoryLabel(
  allowedSchoolTypes: SchoolTypeKey[]
): SchoolCategoryLabel {
  const categories = allowedSchoolTypes.map(getSchoolCategory);
  const uniqueCategories = [...new Set(categories)];

  if (uniqueCategories.length > 1)
    return "PO & VO";

  return uniqueCategories[0] ?? "VO"

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

  return weekdays.map((weekday) => weekdayLabels[weekday]).join(", ");
}

function getVisitPriceEntries(program: ProgramKey): [PriceType, number][] {
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

function getAllowedSchoolTypeLabels(program: ProgramConfig): string[] {
  return program.allowedSchoolTypes.map((schoolType) => {
    return props.config.schoolTypes[schoolType].label;
  });
}
</script>

<template>
  <section class="booking-info-card" aria-labelledby="booking-info-title">
    <div class="booking-info-card__layout">
      <section
        class="booking-info-section booking-info-section--programs"
        aria-labelledby="booking-info-programs-title"
      >
        <header class="booking-info-section__header">
          <h3
            id="booking-info-programs-title"
            class="booking-info-section__title"
          >
            Programma aanbod
          </h3>
        </header>

        <div class="booking-info-section__cards booking-info-section__cards--programs">
          <article
            v-for="program in programEntries"
            :key="program.key"
            class="booking-info-block booking-info-block--program"
          >
            <div class="booking-info-block__main">
              <header class="booking-info-block__header">
                <h4 class="booking-info-block__title">
                  {{ program.label }}
                </h4>

                <p class="booking-info-block__meta-line">
                    {{ getSchoolCategoryLabel(program.allowedSchoolTypes) }}
                </p>
              </header>

              <ul class="booking-info-list booking-info-list--compact">
                <li v-for="line in program.description" :key="line">
                  {{ line }}
                </li>
              </ul>

              <div class="booking-info-subblock">
                <p class="booking-info-block__subtitle">
                  Beschikbaar voor
                </p>

                <ul class="booking-pill-list">
                  <li
                    v-for="schoolTypeLabel in getAllowedSchoolTypeLabels(program)"
                    :key="schoolTypeLabel"
                  >
                    {{ schoolTypeLabel }}
                  </li>
                </ul>
              </div>
            </div>

            <dl class="booking-compact-facts">
              <div>
                <dt>Dagen</dt>
                <dd>{{ formatWeekdays(program.allowedWeekdays) }}</dd>
              </div>

              <div>
                <dt>Kosten</dt>
                <dd>
                  <span
                    v-for="[priceType, price] in getVisitPriceEntries(program.key)"
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
                    v-for="[priceType] in getVisitPriceEntries(program.key)"
                    :key="`${program.key}-${priceType}-students`"
                  >
                    {{ priceTypeLabels[priceType] }}:
                    min.
                    <strong>
                      {{ getMinimumStudents(program.key, priceType) }}
                    </strong>,
                    max.
                    <strong>{{ getMaximumStudents(program.key) }}</strong>
                  </span>
                </dd>
              </div>
            </dl>
          </article>
        </div>
      </section>

      <section
        class="booking-info-section booking-info-section--modules"
        aria-labelledby="booking-info-modules-title"
      >
        <header class="booking-info-section__header">
          <h3
            id="booking-info-modules-title"
            class="booking-info-section__title"
          >
            Modules per programma
          </h3>
        </header>

        <div class="booking-info-section__cards booking-info-section__cards--modules">
          <article
            v-for="item in moduleOverviewItems"
            :key="item.id"
            class="booking-info-block booking-info-block--module"
          >
            <header class="booking-info-block__header">
              <h4 class="booking-info-block__title">
                {{ item.schoolTypeLabel }}
              </h4>

              <p class="booking-info-block__meta-line">
                {{ item.programLabel }}
              </p>
            </header>

            <p class="booking-info-block__subtitle">
              Standaard inbegrepen
            </p>

            <ul
              class="booking-pill-list booking-pill-list--compact booking-pill-list--standard"
            >
              <li v-for="moduleName in item.standaard" :key="moduleName">
                {{ moduleName }}
              </li>
            </ul>

            <template v-if="item.keuze.length > 0">
              <p class="booking-info-block__subtitle">
                Keuzemodules
              </p>

              <ul
                class="booking-pill-list booking-pill-list--compact booking-pill-list--choice"
              >
                <li v-for="moduleName in item.keuze" :key="moduleName">
                  {{ moduleName }}
                </li>
              </ul>
            </template>
          </article>
        </div>
      </section>

      <section
        class="booking-info-section booking-info-section--footer"
        aria-labelledby="booking-info-footer-title"
      >
        <header class="booking-info-section__header">
          <h3
            id="booking-info-footer-title"
            class="booking-info-section__title"
          >
            Inbegrepen en bijzonderheden
          </h3>
        </header>

        <div class="booking-info-section__cards booking-info-section__cards--footer">
          <article class="booking-info-block booking-info-block--highlight">
            <h4 class="booking-info-block__title">
              Inbegrepen bij de onderwijsdag
            </h4>

            <ul class="booking-info-list">
              <li
                v-for="includedItem in config.practicalInfo.included"
                :key="includedItem"
              >
                {{ includedItem }}
              </li>
            </ul>
          </article>

          <article class="booking-info-block">
            <h4 class="booking-info-block__title">
              Bijzonderheden
            </h4>

            <ul class="booking-info-list">
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
          </article>
        </div>
      </section>
    </div>
  </section>
</template>