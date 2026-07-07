<script setup lang="ts">
import { computed } from "vue";
import { CalendarDays, Download, Image } from "lucide-vue-next";

import type { BookingRosterResult } from "../../types/booking/BookingRosterTypes";

const props = defineProps<{
  id: string;
  label: string;
  roster: BookingRosterResult | null;
  isLoading: boolean;
  errorMessage?: string | null;
}>();

const standardModuleText = computed(() => {
  if (!props.roster || props.roster.standardModules.length === 0) {
    return "Geen standaardmodules gevonden.";
  }

  return props.roster.standardModules
    .map((module) => module.label)
    .join(", ");
});

const choiceModuleTitle = computed(() => {
  return props.roster?.choiceModule?.key === "ochtendprogramma"
    ? "Programma"
    : "Gekozen keuzemodule";
});

const unavailableMessage = computed(() => {
  return props.roster?.message ?? "Voor deze combinatie is nog geen voorbeeldrooster beschikbaar.";
});

function getGroupCountLabel(groups: number | null): string {
  if (!groups) return "Aantal groepn onbekend"

  return `${groups} groepen`
}
</script>

<template>
  <section class="roster-preview-field">
    <div
      class="input-label"
      :id="`${id}-label`"
    >
      <span
        class="input-label__icon roster-preview"
        aria-hidden="true"
      >
        <CalendarDays
          :size="17"
          :stroke-width="2.4"
        />
      </span>

      <span>{{ label }}</span>
    </div>

    <div
      class="roster-preview-card"
      :aria-labelledby="`${id}-label`"
    >
      <div class="roster-preview-card__content">
        <div class="roster-preview-card__header">
          <p class="roster-preview-card__title">
            Conceptrooster
          </p>

          <p class="roster-preview-card__description">
            Bekijk het voorbeeldrooster voor de gekozen combinatie.
          </p>
        </div>

        <dl
          v-if="roster"
          class="roster-preview-card__details"
        >
          <div>
            <dt>Aantal groepen</dt>
            <dd>
              <span class="roster-preview-card__value-pill">
                {{ getGroupCountLabel(roster.groupCount) }}
              </span>
            </dd>
          </div>

          <div>
            <dt>Standaard lesmodules</dt>
            <dd class="roster-preview-card__pill-list">
              <span
                v-for="module in roster.standardModules"
                :key="module.key"
                class="roster-preview-card__value-pill"
              >
                {{ module.label }}
              </span>

              <span
                v-if="roster.standardModules.length === 0"
                class="roster-preview-card__value-pill"
              >
                Geen standaardmodules gevonden
              </span>
            </dd>
          </div>

          <div>
            <dt>{{ choiceModuleTitle }}</dt>
            <dd>
              <span class="roster-preview-card__value-pill">
                {{ roster.choiceModule?.label ?? "Geen losse keuzemodule" }}
              </span>
            </dd>
          </div>
        </dl>

        <div
          v-if="isLoading"
          class="roster-preview-card__notice"
        >
          Conceptrooster ophalen...
        </div>

        <div
          v-else-if="errorMessage"
          class="roster-preview-card__notice roster-preview-card__notice--error"
        >
          {{ errorMessage }}
        </div>

        <div
          v-else-if="roster && !roster.available"
          class="roster-preview-card__notice"
        >
          {{ unavailableMessage }}
        </div>
      </div>

      <div class="roster-preview-card__media">
        <figure
          v-if="roster?.available && roster.imageUrl"
          class="roster-preview-card__figure"
        >
          <img
            :src="roster.imageUrl"
            alt="Preview van het conceptrooster"
            loading="lazy"
          />
        </figure>

        <div
          v-else
          class="roster-preview-card__placeholder"
          aria-hidden="true"
        >
          <Image
            :size="30"
            :stroke-width="1.9"
          />
        </div>

        <a
          v-if="roster?.available && roster.pdfUrl"
          class="roster-preview-card__download"
          :href="roster.pdfUrl"
          target="_blank"
          rel="noopener"
        >
          <Download
            :size="17"
            :stroke-width="2.4"
            aria-hidden="true"
          />
          <span>Download rooster</span>
        </a>
      </div>
    </div>
  </section>
</template>

<style scoped>
.roster-preview-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: var(--field-gap);
  margin-top: 1.05rem;
  margin-bottom: var(--field-stack-gap);
}

.input-label__icon.roster-preview {
  display: inline-flex;
  align-items: center;
  color: var(--color-main-blue);
}

.roster-preview-card {
  display: grid;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid rgba(38, 57, 111, 0.16);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background:
    radial-gradient(
      circle at 100% 0%,
      rgba(38, 57, 111, 0.075),
      transparent 32%
    ),
    radial-gradient(
      circle at 0% 100%,
      rgba(150, 177, 220, 0.16),
      transparent 36%
    ),
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.99),
      rgba(246, 249, 255, 0.97)
    );
  box-shadow:
    0 0.55rem 1.15rem rgba(8, 21, 64, 0.065),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.roster-preview-card__content {
  display: grid;
  align-content: start;
  gap: 0.85rem;
}

.roster-preview-card__header {
  display: grid;
  gap: 0.35rem;
}

.roster-preview-card__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.2;
}

.roster-preview-card__description {
  max-width: 62ch;
  margin: 0;
  color: rgba(8, 21, 64, 0.7);
  font-size: 0.92rem;
  line-height: 1.45;
}

.roster-preview-card__details {
  display: grid;
  gap: 0.7rem;
  margin: 0;
}


.roster-preview-card__details div {
  display: grid;
  gap: 0.42rem;
    padding: 0.72rem 0.85rem;
  border: 1px solid rgba(38, 57, 111, 0.12);
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.99),
      rgba(243, 248, 255, 0.92)
    );
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    0 0.18rem 0.45rem rgba(8, 21, 64, 0.035);
}


.roster-preview-card__details div::before {
  content: "";
  position: absolute;
  top: 0.85rem;
  left: 0.72rem;
  width: 0.38rem;
  height: 0.38rem;
  border-radius: 999px;
  background: rgba(184, 151, 92, 0.72);
  box-shadow: 0 0 0 0.22rem rgba(184, 151, 92, 0.12);
}

.roster-preview-card__details dt {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.48rem;
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 0.72rem;
  font-weight: 850;
  line-height: 1.2;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.roster-preview-card__details dt::before {
  content: "";
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: var(--color-main-blue);
  box-shadow: 0 0 0 0.16rem rgba(38, 57, 111, 0.12);
  flex: 0 0 auto;
}

.roster-preview-card__pill-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.48rem;
}

.roster-preview-card__value-pill {
  display: inline-flex;
  align-items: center;
  min-height: 2.15rem;
  padding: 0.34rem 0.8rem;
  border: 1px solid rgba(8, 21, 64, 0.32);
  border-radius: 999px;
  background:
    linear-gradient(
      180deg,
      rgba(255, 252, 247, 0.98),
      rgba(250, 244, 233, 0.96)
    );
  color: rgba(8, 21, 64, 0.95);
  font-size: 0.72rem;
  font-weight: 800;
  line-height: 1.2;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.82),
    0 0.05rem 0.16rem rgba(8, 21, 64, 0.035);
}


.roster-preview-card__details dd {
  margin: 0;
}



.roster-preview-card__media {
  display: grid;
  width: 100%;
  gap: 0.75rem;
}

.roster-preview-card__figure,
.roster-preview-card__placeholder {
  width: 100%;
  min-height: 18rem;
  margin: 0;
  overflow: hidden;
  border: 1px solid rgba(38, 57, 111, 0.14);
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      180deg,
      rgba(255, 255, 255, 0.98),
      rgba(247, 250, 255, 0.96)
    );
  box-shadow:
    0 0.44rem 0.95rem rgba(8, 21, 64, 0.08),
    inset 0 1px 0 rgba(255, 255, 255, 0.88);
}

.roster-preview-card__figure img {
  display: block;
  width: 100%;
  height: auto;
  object-fit: contain;
}

.roster-preview-card__placeholder {
  display: grid;
  place-items: center;
  color: rgba(38, 57, 111, 0.46);
}

.roster-preview-card__download {
  display: inline-flex;
  width: 100%;
  min-height: 2.65rem;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.68rem 0.9rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      135deg,
      var(--color-main-blue),
      var(--color-main-blue-dark)
    );
  color: #fff;
  font-size: 0.9rem;
  font-weight: 850;
  line-height: 1.1;
  text-decoration: none;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.16),
    0 0.35rem 0.75rem rgba(8, 21, 64, 0.14);
  transition:
    box-shadow var(--field-transition),
    transform var(--field-transition),
    filter var(--field-transition);
}

.roster-preview-card__download:hover {
  filter: brightness(1.05);
  transform: translateY(-1px);
}

.roster-preview-card__download:focus-visible {
  outline: none;
  box-shadow: var(--shadow-control-focus);
}

.roster-preview-card__notice {
  padding: 0.78rem 0.9rem;
  border: 1px solid rgba(38, 57, 111, 0.12);
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(243, 248, 255, 0.9)
    );
  color: rgba(8, 21, 64, 0.74);
  font-size: 0.9rem;
  font-weight: 720;
  line-height: 1.35;
}

.roster-preview-card__notice--error {
  border-color: rgba(217, 49, 52, 0.24);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(255, 246, 246, 0.88)
    );
  color: rgba(126, 28, 31, 0.86);
}

@media (max-width: 820px) {
  .roster-preview-card__figure,
  .roster-preview-card__placeholder {
    min-height: 12rem;
  }
}
</style>
