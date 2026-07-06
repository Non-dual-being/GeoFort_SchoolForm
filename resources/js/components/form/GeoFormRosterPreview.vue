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
            <dd>{{ roster.groupCount ?? "Nog niet bepaald" }}</dd>
          </div>

          <div>
            <dt>Standaard lesmodules</dt>
            <dd>{{ standardModuleText }}</dd>
          </div>

          <div>
            <dt>{{ choiceModuleTitle }}</dt>
            <dd>{{ roster.choiceModule?.label ?? "Geen losse keuzemodule" }}</dd>
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
  gap: 1.05rem;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-left: 0.28rem solid rgba(8, 21, 64, 0.9);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background:
    radial-gradient(
      circle at 100% 4%,
      rgba(239, 151, 63, 0.12),
      transparent 26%
    ),
    radial-gradient(
      circle at 0% 0%,
      rgba(38, 57, 111, 0.08),
      transparent 34%
    ),
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.99),
      rgba(246, 249, 255, 0.96)
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
  margin: 0.35rem 0 0;
  color: rgba(8, 21, 64, 0.72);
  font-size: 0.92rem;
  line-height: 1.45;
}

.roster-preview-card__download {
  display: inline-flex;
  width: 100%;
  min-height: 2.55rem;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.65rem 0.85rem;
  border: 1px solid rgba(38, 57, 111, 0.22);
  border-radius: var(--field-radius);
  background: var(--color-main-blue);
  color: #fff;
  font-size: 0.9rem;
  font-weight: 850;
  line-height: 1.1;
  text-decoration: none;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.16),
    0 0.35rem 0.75rem rgba(8, 21, 64, 0.12);
  transition:
    background-color var(--field-transition),
    box-shadow var(--field-transition),
    transform var(--field-transition);
}

.roster-preview-card__download:hover {
  background: var(--color-main-blue-dark);
  transform: translateY(-1px);
}

.roster-preview-card__download:focus-visible {
  outline: none;
  box-shadow: var(--shadow-control-focus);
}

.roster-preview-card__details {
  display: grid;
  gap: 0;
  margin: 0;
  overflow: hidden;
  border: 1px solid rgba(38, 57, 111, 0.13);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.68);
}

.roster-preview-card__details div {
  display: grid;
  grid-template-columns: minmax(8.5rem, 0.35fr) minmax(0, 1fr);
  gap: 0.75rem;
  padding: 0.72rem 0.85rem;
  border-bottom: 1px solid rgba(38, 57, 111, 0.1);
}

.roster-preview-card__details div:last-child {
  border-bottom: 0;
}

.roster-preview-card__details dt {
  color: rgba(8, 21, 64, 0.58);
  font-size: 0.78rem;
  font-weight: 850;
}

.roster-preview-card__details dd {
  margin: 0;
  color: rgba(8, 21, 64, 0.82);
  font-size: 0.92rem;
  font-weight: 700;
  line-height: 1.35;
}

.roster-preview-card__media {
  display: grid;
  width: 100%;
  max-width: 54rem;
  gap: 0.75rem;
  justify-self: center;
}

.roster-preview-card__notice {
  padding: 0.74rem 0.85rem;
  border: 1px solid rgba(38, 57, 111, 0.13);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.72);
  color: rgba(8, 21, 64, 0.74);
  font-size: 0.9rem;
  font-weight: 720;
  line-height: 1.35;
}

.roster-preview-card__notice--error {
  border-color: rgba(217, 49, 52, 0.34);
  color: rgba(126, 28, 31, 0.86);
}

.roster-preview-card__figure,
.roster-preview-card__placeholder {
  width: 100%;
  min-height: 18rem;
  margin: 0;
  overflow: hidden;
  border: 1px solid rgba(8, 21, 64, 0.13);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.72);
  box-shadow:
    0 0.44rem 0.95rem rgba(8, 21, 64, 0.08),
    inset 0 1px 0 rgba(255, 255, 255, 0.85);
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

@media (max-width: 820px) {
  .roster-preview-card__figure,
  .roster-preview-card__placeholder {
    min-height: 12rem;
  }
}

  .roster-preview-card__details div {
    grid-template-columns: 1fr;
    gap: 0.2rem;
  }

</style>
