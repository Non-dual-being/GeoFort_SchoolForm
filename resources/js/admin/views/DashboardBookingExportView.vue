<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { Download } from "lucide-vue-next";

import AdminButton from "../components/form/AdminButton.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import {
  downloadDashboardBookingsCsv,
  fetchBookingExportBounds,
  fetchBookingExportSummary,
} from "../services/dashboardBookingExportApi";
import type {
  BookingExportBounds,
  BookingExportSummaryResponse,
} from "../types/bookingExport";

const bounds = ref<BookingExportBounds>({ minDate: null, maxDate: null });
const startDate = ref("");
const endDate = ref("");
const metadataLoading = ref(true);
const metadataError = ref(false);
const summary = ref<BookingExportSummaryResponse | null>(null);
const summaryLoading = ref(false);
const summaryError = ref(false);
const downloading = ref(false);
const downloadError = ref("");
const downloadSuccess = ref("");
const summaryErrorElement = ref<HTMLElement | null>(null);
const downloadErrorElement = ref<HTMLElement | null>(null);

let metadataController: AbortController | undefined;
let summaryController: AbortController | undefined;
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let summarySequence = 0;
let lastRequestedPeriod = "";

const numberFormatter = new Intl.NumberFormat("nl-NL", {
  maximumFractionDigits: 1,
});

const hasAvailableBookings = computed(() =>
  bounds.value.minDate !== null && bounds.value.maxDate !== null);
const periodError = computed(() =>
  startDate.value && endDate.value && startDate.value > endDate.value
    ? "De einddatum mag niet vóór de begindatum liggen."
    : "");
const periodComplete = computed(() =>
  startDate.value !== "" && endDate.value !== "" && periodError.value === "");
const canDownload = computed(() =>
  periodComplete.value
  && !summaryLoading.value
  && !summaryError.value
  && summary.value !== null
  && summary.value.period.hasOverlap
  && summary.value.summary.requestStats.total > 0
  && !downloading.value);
const availableRangeLabel = computed(() => {
  if (!hasAvailableBookings.value) return "";
  return `Beschikbare bezoekdatums: ${formatLongDate(bounds.value.minDate!)} t/m ${formatLongDate(bounds.value.maxDate!)}.`;
});
const effectivePeriodLabel = computed(() => {
  const period = summary.value?.period;
  if (!period?.effectiveStartDate || !period.effectiveEndDate) {
    return `${formatDate(startDate.value)} t/m ${formatDate(endDate.value)}`;
  }
  return `${formatDate(period.effectiveStartDate)} t/m ${formatDate(period.effectiveEndDate)}`;
});

async function loadMetadata(): Promise<void> {
  metadataController?.abort();
  const controller = new AbortController();
  metadataController = controller;
  metadataLoading.value = true;
  metadataError.value = false;
  try {
    const result = await fetchBookingExportBounds(controller.signal);
    bounds.value = result.bounds;
    if (result.bounds.minDate && result.bounds.maxDate) {
      startDate.value = result.bounds.minDate;
      endDate.value = result.bounds.maxDate;
    }
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    metadataError.value = true;
  } finally {
    if (!controller.signal.aborted) metadataLoading.value = false;
  }
}

function scheduleSummary(): void {
  clearTimeout(debounceTimer);
  summaryController?.abort();
  summaryError.value = false;
  downloadError.value = "";
  downloadSuccess.value = "";
  if (!periodComplete.value || !hasAvailableBookings.value) {
    summary.value = null;
    summaryLoading.value = false;
    return;
  }
  const key = `${startDate.value}|${endDate.value}`;
  if (key === lastRequestedPeriod && summary.value !== null) return;
  summaryLoading.value = true;
  debounceTimer = setTimeout(() => void loadSummary(key), 300);
}

async function loadSummary(key = `${startDate.value}|${endDate.value}`): Promise<void> {
  if (!periodComplete.value || !hasAvailableBookings.value) return;
  summaryController?.abort();
  const controller = new AbortController();
  summaryController = controller;
  const sequence = ++summarySequence;
  summaryLoading.value = true;
  summaryError.value = false;
  try {
    const result = await fetchBookingExportSummary(
      startDate.value,
      endDate.value,
      controller.signal,
    );
    if (sequence !== summarySequence) return;
    summary.value = result;
    lastRequestedPeriod = key;
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    if (sequence !== summarySequence) return;
    summaryError.value = true;
    summary.value = null;
    await nextTick();
    summaryErrorElement.value?.focus();
  } finally {
    if (sequence === summarySequence && !controller.signal.aborted) {
      summaryLoading.value = false;
    }
  }
}

function resetFullPeriod(): void {
  if (!bounds.value.minDate || !bounds.value.maxDate) return;
  startDate.value = bounds.value.minDate;
  endDate.value = bounds.value.maxDate;
}

async function downloadCsv(): Promise<void> {
  const period = summary.value?.period;
  if (!canDownload.value || !period?.effectiveStartDate || !period.effectiveEndDate) return;
  downloading.value = true;
  downloadError.value = "";
  downloadSuccess.value = "";
  try {
    const filename = await downloadDashboardBookingsCsv(
      period.effectiveStartDate,
      period.effectiveEndDate,
    );
    downloadSuccess.value = `${filename} is gedownload.`;
  } catch (error) {
    downloadError.value = error instanceof Error
      ? error.message
      : "Exporteren is niet gelukt. Probeer het opnieuw.";
    await nextTick();
    downloadErrorElement.value?.focus();
  } finally {
    downloading.value = false;
  }
}

function formatNumber(value: number): string {
  return numberFormatter.format(value);
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

function formatLongDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number);
  if (!year || !month || !day) return value;
  return new Intl.DateTimeFormat("nl-NL", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

watch([startDate, endDate], scheduleSummary);
onMounted(() => void loadMetadata());
onBeforeUnmount(() => {
  clearTimeout(debounceTimer);
  metadataController?.abort();
  summaryController?.abort();
});
</script>

<template>
  <section class="admin-export-page" aria-labelledby="export-page-title">
    <header class="admin-export-page__intro">
      <p class="admin-eyebrow">Planning</p>
      <h1 id="export-page-title">Boekingen exporteren</h1>
      <p>
        Selecteer een bezoekperiode, controleer de samenvatting en download
        daarna de volledige administratie als CSV.
      </p>
    </header>

    <div
      v-if="metadataLoading"
      class="admin-export-state"
      role="status"
      aria-live="polite"
    >
      Beschikbare bezoekdatums laden…
    </div>

    <div
      v-else-if="metadataError"
      class="admin-export-state"
      role="alert"
    >
      <p>De beschikbare bezoekdatums konden niet worden geladen.</p>
      <AdminButton @click="loadMetadata">Opnieuw proberen</AdminButton>
    </div>

    <div
      v-else-if="!hasAvailableBookings"
      class="admin-export-state"
    >
      <h2>Nog geen boekingen beschikbaar</h2>
      <p>Er zijn geen boekingen met een geldige bezoekdatum om samen te vatten of te exporteren.</p>
    </div>

    <template v-else>
      <section class="admin-export-section" aria-labelledby="export-period-title">
        <p class="admin-eyebrow">Periode</p>
        <h2 id="export-period-title">Selecteer bezoekperiode</h2>
        <p>Alleen aanvragen met een bezoekdatum binnen deze periode worden meegenomen.</p>

        <div class="admin-export-period__fields">
          <AdminDateField
            v-model="startDate"
            name="export-start-date"
            label="Begindatum"
            :min="bounds.minDate || undefined"
            :max="endDate || bounds.maxDate || undefined"
            :error="periodError ? 'Controleer de gekozen periode.' : null"
            required
          />
          <AdminDateField
            v-model="endDate"
            name="export-end-date"
            label="Einddatum"
            :min="startDate || bounds.minDate || undefined"
            :max="bounds.maxDate || undefined"
            :error="periodError || null"
            required
          />
        </div>

        <div class="admin-export-period__footer">
          <p>{{ availableRangeLabel }}</p>
          <AdminButton variant="secondary" @click="resetFullPeriod">
            Volledige periode
          </AdminButton>
        </div>
      </section>

      <section class="admin-export-section" aria-labelledby="export-summary-title">
        <p class="admin-eyebrow">Overzicht</p>
        <h2 id="export-summary-title">Geselecteerde periode</h2>
        <p>{{ effectivePeriodLabel }}</p>
        <p
          v-if="summary?.period.normalized"
          class="admin-export-normalized"
        >
          De aangevraagde periode is begrensd tot de beschikbare bezoekdatums.
        </p>

        <div
          v-if="summaryLoading"
          class="admin-export-categories"
          role="status"
          aria-live="polite"
        >
          <span class="sr-only">Samenvatting laden…</span>
          <article
            v-for="category in 4"
            :key="category"
            class="admin-export-category admin-export-category--loading"
            aria-hidden="true"
          >
            <span class="admin-export-skeleton admin-export-skeleton--title" />
            <span
              v-for="metric in 6"
              :key="metric"
              class="admin-export-skeleton"
            />
          </article>
        </div>
        <div
          v-else-if="summaryError"
          ref="summaryErrorElement"
          class="admin-export-state"
          role="alert"
          tabindex="-1"
        >
          <p>De samenvatting kon niet worden geladen.</p>
          <AdminButton @click="loadSummary()">Opnieuw proberen</AdminButton>
        </div>
        <div
          v-else-if="summary && summary.summary.requestStats.total === 0"
          class="admin-export-state"
        >
          Geen boekingen binnen deze periode.
        </div>
        <template v-else-if="summary">
          <div class="admin-export-categories">
            <article class="admin-export-category" aria-labelledby="export-category-requests">
              <h3 id="export-category-requests">Aanvragen</h3>
              <p>Aantallen boekingen, scholen en bezoekdagen.</p>
              <dl class="admin-export-metrics">
                <div class="admin-export-metric admin-export-metric--primary">
                  <dt>Totaal aanvragen</dt>
                  <dd>{{ formatNumber(summary.summary.requestStats.total) }}</dd>
                </div>
                <div><dt>Definitief</dt><dd>{{ formatNumber(summary.summary.requestStats.status.confirmed) }}</dd></div>
                <div><dt>In optie</dt><dd>{{ formatNumber(summary.summary.requestStats.status.option) }}</dd></div>
                <div><dt>Afgewezen</dt><dd>{{ formatNumber(summary.summary.requestStats.status.rejected) }}</dd></div>
                <div v-if="summary.summary.requestStats.status.other > 0"><dt>Overig</dt><dd>{{ formatNumber(summary.summary.requestStats.status.other) }}</dd></div>
                <div><dt>Unieke scholen</dt><dd>{{ formatNumber(summary.summary.requestStats.uniqueSchools) }}</dd></div>
                <div><dt>Bezoekdagen</dt><dd>{{ formatNumber(summary.summary.requestStats.visitDays) }}</dd></div>
              </dl>
            </article>

            <article class="admin-export-category" aria-labelledby="export-category-students">
              <h3 id="export-category-students">Leerlingen</h3>
              <p>Gepland en sectoren zijn gebaseerd op {{ summary.summary.activeStatusLabels.join(" en ") }}.</p>
              <dl class="admin-export-metrics">
                <div class="admin-export-metric admin-export-metric--primary">
                  <dt>Geplande leerlingen</dt>
                  <dd>{{ formatNumber(summary.summary.studentStats.planned) }}</dd>
                </div>
                <div><dt>Totaal in selectie</dt><dd>{{ formatNumber(summary.summary.studentStats.totalInSelection) }}</dd></div>
                <div v-for="sector in summary.summary.studentStats.sectors" :key="sector.key">
                  <dt>{{ sector.label }}</dt>
                  <dd>{{ formatNumber(sector.students) }}</dd>
                </div>
                <div><dt>Definitieve leerlingen</dt><dd>{{ formatNumber(summary.summary.studentStats.confirmed) }}</dd></div>
                <div><dt>Leerlingen in optie</dt><dd>{{ formatNumber(summary.summary.studentStats.option) }}</dd></div>
                <div><dt>Gemiddeld per actieve aanvraag</dt><dd>{{ formatNumber(summary.summary.studentStats.averagePerActiveBooking) }}</dd></div>
              </dl>
            </article>

            <article class="admin-export-category" aria-labelledby="export-category-program">
              <h3 id="export-category-program">Programma</h3>
              <p>Operationele verdeling van {{ summary.summary.activeStatusLabels.join(" en ") }}.</p>
              <dl class="admin-export-metrics">
                <div v-for="program in [summary.summary.programStats.day, summary.summary.programStats.morning]" :key="program.key">
                  <dt>{{ program.label }}</dt>
                  <dd>{{ formatNumber(program.bookings) }} <small class="admin-export-metric__share">· {{ program.percentage }}%</small></dd>
                </div>
                <div><dt>Leerlingen dagprogramma</dt><dd>{{ formatNumber(summary.summary.programStats.day.students) }}</dd></div>
                <div><dt>Leerlingen ochtendprogramma</dt><dd>{{ formatNumber(summary.summary.programStats.morning.students) }}</dd></div>
                <div><dt>Gemiddeld dagprogramma</dt><dd>{{ formatNumber(summary.summary.programStats.day.averageStudents) }}</dd></div>
                <div><dt>Gemiddeld ochtendprogramma</dt><dd>{{ formatNumber(summary.summary.programStats.morning.averageStudents) }}</dd></div>
                <div><dt>Drukste bezoekdag</dt><dd>{{ formatNumber(summary.summary.programStats.maximumStudentsOneVisitDay) }}</dd></div>
              </dl>
              <h4>Keuzemodules dagprogramma</h4>
              <p class="admin-export-category__note">
                Verdeling over {{ formatNumber(summary.summary.programStats.choiceModules.eligibleBookings) }} actieve dagprogramma’s.
                <template
                  v-if="summary.summary.programStats.choiceModules.recordedChoices
                    < summary.summary.programStats.choiceModules.eligibleBookings"
                >
                  Voor {{ formatNumber(summary.summary.programStats.choiceModules.recordedChoices) }} daarvan is een keuze opgeslagen.
                </template>
              </p>
              <dl v-if="summary.summary.programStats.choiceModules.distribution.length" class="admin-export-metrics admin-export-metrics--subsection">
                <div v-for="module in summary.summary.programStats.choiceModules.distribution" :key="module.label">
                  <dt>{{ module.label }}</dt>
                  <dd>{{ formatNumber(module.bookings) }} <small class="admin-export-metric__share">· {{ module.percentage }}%</small></dd>
                </div>
              </dl>
              <p v-else class="admin-export-category__empty">Geen modulekeuzes in deze periode.</p>
            </article>

            <article class="admin-export-category" aria-labelledby="export-category-composition">
              <h3 id="export-category-composition">Onderwijsprofiel</h3>
              <p>Actieve aanvragen; niveaus alleen voor VO om vertekening door de PO-regel te voorkomen.</p>
              <dl class="admin-export-metrics">
                <div>
                  <dt>VO: één niveau</dt>
                  <dd>{{ formatNumber(summary.summary.compositionStats.levels.one.bookings) }} <small class="admin-export-metric__share">· {{ summary.summary.compositionStats.levels.one.percentage }}%</small></dd>
                </div>
                <div>
                  <dt>VO: meerdere niveaus</dt>
                  <dd>{{ formatNumber(summary.summary.compositionStats.levels.multiple.bookings) }} <small class="admin-export-metric__share">· {{ summary.summary.compositionStats.levels.multiple.percentage }}%</small></dd>
                </div>
                <div>
                  <dt>Eén geselecteerde groep</dt>
                  <dd>{{ formatNumber(summary.summary.compositionStats.groups.one.bookings) }} <small class="admin-export-metric__share">· {{ summary.summary.compositionStats.groups.one.percentage }}%</small></dd>
                </div>
                <div>
                  <dt>Twee geselecteerde groepen</dt>
                  <dd>{{ formatNumber(summary.summary.compositionStats.groups.two.bookings) }} <small class="admin-export-metric__share">· {{ summary.summary.compositionStats.groups.two.percentage }}%</small></dd>
                </div>
                <div>
                  <dt>Drie of meer geselecteerde groepen</dt>
                  <dd>{{ formatNumber(summary.summary.compositionStats.groups.threeOrMore.bookings) }} <small class="admin-export-metric__share">· {{ summary.summary.compositionStats.groups.threeOrMore.percentage }}%</small></dd>
                </div>
                <div><dt>Gemiddeld geselecteerde groepen</dt><dd>{{ formatNumber(summary.summary.compositionStats.groups.averagePerBooking) }}</dd></div>
              </dl>
            </article>
          </div>
        </template>
      </section>

      <section class="admin-export-section admin-export-download" aria-labelledby="export-download-title">
        <div>
          <p class="admin-eyebrow">Download</p>
          <h2 id="export-download-title">CSV-export</h2>
          <p>
            Download de volledige administratie van de geselecteerde periode.
            Het bestand bevat school- en contactgegevens, onderwijsselectie,
            catering, CJP en operationele opmerkingen.
          </p>
          <dl class="admin-export-download__details">
            <div><dt>Periode</dt><dd>{{ effectivePeriodLabel }}</dd></div>
            <div><dt>Boekingen</dt><dd>{{ formatNumber(summary?.summary.requestStats.total ?? 0) }}</dd></div>
            <div><dt>Sortering</dt><dd>{{ summary?.period.sortLabel ?? "Bezoekdatum oplopend, daarna boeking-ID" }}</dd></div>
          </dl>
          <p class="admin-export-privacy">
            Dit bestand bevat persoonsgegevens. Bewaar en deel het alleen volgens de geldende GeoFort-afspraken.
          </p>
          <p
            v-if="downloadError"
            ref="downloadErrorElement"
            class="admin-export-error"
            role="alert"
            tabindex="-1"
          >
            {{ downloadError }}
          </p>
          <p v-if="downloadSuccess" role="status">{{ downloadSuccess }}</p>
        </div>
        <AdminButton
          class="admin-export-download__button"
          :disabled="!canDownload"
          :loading="downloading"
          @click="downloadCsv"
        >
          <Download :size="18" aria-hidden="true" />
          {{ downloading ? "CSV voorbereiden…" : "CSV downloaden" }}
        </AdminButton>
      </section>
    </template>
  </section>
</template>
