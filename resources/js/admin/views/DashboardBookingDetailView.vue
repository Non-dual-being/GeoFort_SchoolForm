<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import { fetchDashboardBookingDetail } from "../services/dashboardBookingDetailApi";
import type { DashboardBookingDetail } from "../types/bookingDetail";
import { ApiError } from "../../services/http/apiClient";
import BookingStatusPanel from "../components/bookings/BookingStatusPanel.vue";
import type { BookingStatusChangeCode } from "../types/bookingStatus";

const route = useRoute();
const booking = ref<DashboardBookingDetail | null>(null);
const loading = ref(false);
const notFound = ref(false);
const error = ref(false);
const statusFeedback = ref<{ code: BookingStatusChangeCode; message: string } | null>(null);
let controller: AbortController | undefined;

const bookingId = computed(() => {
  const value = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
  const id = Number(value);
  return typeof value === "string" && /^\d+$/.test(value) && Number.isSafeInteger(id) && id > 0
    ? id
    : null;
});

const listLocation = computed(() => ({ name: "bookings", query: route.query }));

async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  booking.value = null;
  notFound.value = bookingId.value === null;
  error.value = false;

  if (bookingId.value === null) return;

  loading.value = true;
  try {
    const result = await fetchDashboardBookingDetail(bookingId.value, requestController.signal);
    booking.value = result.booking;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    notFound.value = requestError instanceof ApiError && [400, 404].includes(requestError.status);
    error.value = !notFound.value;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

async function statusCompleted(code: BookingStatusChangeCode, message: string, refresh: boolean): Promise<void> {
  if (refresh) await load();
  statusFeedback.value = { code, message };
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number);
  if (!year || !month || !day) return value;
  return new Intl.DateTimeFormat("nl-NL", {
    day: "numeric", month: "long", year: "numeric", timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

function statusClass(status: string): string {
  if (status === "Definitief") return "admin-status--confirmed";
  if (status === "Afgewezen") return "admin-status--rejected";
  return "admin-status--option";
}

function display(value: string | number | null): string {
  return value === null || String(value).trim() === "" ? "Niet opgegeven" : String(value);
}

function hasValue(value: string | null): value is string {
  return value !== null && value.trim() !== "";
}

function combinedDisplay(values: readonly (string | null | undefined)[]): string {
  const combined = values
    .filter((value): value is string => typeof value === "string" && value.trim() !== "")
    .map((value) => value.trim())
    .join(" ");

  return combined || "Niet opgegeven";
}

function yesNo(value: boolean): string {
  return value ? "Ja" : "Nee";
}

watch(bookingId, () => { void load(); }, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-booking-detail" aria-labelledby="booking-detail-title">
    <RouterLink class="admin-booking-detail__back" :to="listLocation">
      ← Terug naar aanvragen
    </RouterLink>

    <div v-if="loading" class="admin-booking-detail__loading" role="status" aria-live="polite">
      <span class="admin-booking-detail__skeleton admin-booking-detail__skeleton--title" />
      <span class="admin-booking-detail__skeleton" />
      <span class="admin-booking-detail__skeleton" />
      <span class="admin-booking-detail__sr-only">Aanvraag laden…</span>
    </div>

    <div v-else-if="notFound" class="admin-bookings__state admin-booking-detail__state">
      <p class="admin-eyebrow">Aanvraag niet gevonden</p>
      <h1 id="booking-detail-title">Deze aanvraag bestaat niet</h1>
      <p>Controleer het aanvraagnummer of ga terug naar het overzicht.</p>
      <RouterLink class="admin-button admin-button--secondary" :to="listLocation">Terug naar aanvragen</RouterLink>
    </div>

    <div v-else-if="error" class="admin-bookings__state admin-booking-detail__state" role="alert">
      <p class="admin-eyebrow">Laden mislukt</p>
      <h1 id="booking-detail-title">Aanvraag kon niet worden geladen</h1>
      <p>Probeer het opnieuw. Er zijn geen gegevens gewijzigd.</p>
      <AdminButton @click="load">Opnieuw proberen</AdminButton>
    </div>

    <template v-else-if="booking">
      <header class="admin-booking-detail__header">
        <div>
          <p class="admin-eyebrow">Onderwijsformulier 2.0</p>
          <h1 id="booking-detail-title">Aanvraag #{{ booking.id }}</h1>
          <p>{{ booking.school.name }} · {{ formatDate(booking.visitDate) }}</p>
        </div>
        <span class="admin-status" :class="statusClass(booking.status)">{{ booking.status }}</span>
      </header>

      <div class="admin-booking-detail__grid">
        <div v-if="statusFeedback" class="admin-booking-detail__wide admin-status-feedback"
          :class="statusFeedback.code === 'SUCCESS' ? 'admin-status-feedback--success' : 'admin-status-feedback--error'"
          :role="statusFeedback.code === 'SUCCESS' ? 'status' : 'alert'">
          {{ statusFeedback.message }}
        </div>
        <BookingStatusPanel :booking-id="booking.id" :current-status="booking.status" @completed="statusCompleted" />
        <section class="admin-card">
          <h2>Schoolgegevens</h2>
          <dl class="admin-details">
            <dt>School</dt><dd>{{ display(booking.school.name) }}</dd>
            <dt>Adres</dt><dd>{{ display(booking.school.address) }}</dd>
            <dt>Postcode en plaats</dt><dd>{{ combinedDisplay([booking.school.postalCode, booking.school.city]) }}</dd>
            <dt>Land</dt><dd>{{ display(booking.school.country) }}</dd>
            <dt>Telefoon</dt>
            <dd>
              <a v-if="hasValue(booking.school.phone)" :href="`tel:${booking.school.phone.trim()}`">{{ booking.school.phone }}</a>
              <template v-else>Niet opgegeven</template>
            </dd>
          </dl>
        </section>

        <section class="admin-card">
          <h2>Contactpersoon</h2>
          <dl class="admin-details">
            <dt>Naam</dt><dd>{{ combinedDisplay([booking.contact.firstName, booking.contact.lastName]) }}</dd>
            <dt>E-mail</dt>
            <dd>
              <a v-if="hasValue(booking.contact.email)" :href="`mailto:${booking.contact.email.trim()}`">{{ booking.contact.email }}</a>
              <template v-else>Niet opgegeven</template>
            </dd>
            <dt>Telefoon</dt>
            <dd>
              <a v-if="hasValue(booking.contact.phone)" :href="`tel:${booking.contact.phone.trim()}`">{{ booking.contact.phone }}</a>
              <template v-else>Niet opgegeven</template>
            </dd>
          </dl>
        </section>

        <section class="admin-card admin-booking-detail__wide">
          <h2>Onderwijsprogramma</h2>
          <dl class="admin-details">
            <dt>Sector</dt><dd>{{ booking.education.sectorLabel }}</dd>
            <dt>Programma</dt><dd>{{ booking.education.programLabel }}</dd>
            <dt>Keuzemodule</dt><dd>{{ display(booking.education.moduleLabel) }}</dd>
          </dl>
          <div v-if="booking.education.selections.length" class="admin-booking-detail__selections">
            <div v-for="selection in booking.education.selections" :key="selection.levelKey">
              <h3>{{ selection.levelLabel }}</h3>
              <ul><li v-for="group in selection.groups" :key="group.groupKey">{{ group.groupLabel }}</li></ul>
            </div>
          </div>
          <p v-else class="admin-booking-detail__muted">Geen onderwijsniveaus of groepen vastgelegd.</p>
        </section>

        <section class="admin-card">
          <h2>Aantallen</h2>
          <dl class="admin-details">
            <dt>Leerlingen</dt><dd>{{ display(booking.education.studentCount) }}</dd>
            <dt>Begeleiders</dt><dd>{{ display(booking.education.supervisorCount) }}</dd>
          </dl>
        </section>

        <section class="admin-card">
          <h2>Eten en drinken</h2>
          <dl class="admin-details">
            <dt>Remise break</dt><dd>{{ booking.foodAndDrink.remiseBreak }}</dd>
            <dt>Kazerne break</dt><dd>{{ booking.foodAndDrink.kazerneBreak }}</dd>
            <dt>Fortgracht break</dt><dd>{{ booking.foodAndDrink.fortgrachtBreak }}</dd>
            <dt>Waterijsje</dt><dd>{{ booking.foodAndDrink.waterIce }}</dd>
            <dt>Glas limonade</dt><dd>{{ booking.foodAndDrink.lemonade }}</dd>
            <dt>Remiselunch</dt><dd>{{ booking.foodAndDrink.remiseLunch }}</dd>
            <dt>Eigen picknick</dt><dd>{{ yesNo(booking.foodAndDrink.ownPicnic) }}</dd>
          </dl>
        </section>

        <section class="admin-card admin-booking-detail__wide">
          <h2>Aanvullende informatie</h2>
          <dl class="admin-details">
            <dt>CJP-korting</dt><dd>{{ yesNo(booking.additional.cjpDiscount) }}</dd>
            <template v-if="booking.additional.cjpDiscount">
              <dt>CJP-contactpersoon</dt><dd>{{ display(booking.additional.cjpContactName) }}</dd>
              <dt>CJP-pasnummer</dt><dd>{{ display(booking.additional.cjpCardNumber) }}</dd>
            </template>
            <dt>Hoe kent u GeoFort?</dt><dd>{{ display(booking.additional.referralSource) }}</dd>
            <dt>Opmerkingen</dt><dd class="admin-booking-detail__remarks">{{ display(booking.additional.remarks) }}</dd>
          </dl>
        </section>

        <section class="admin-card admin-booking-detail__wide">
          <h2>Herkomst aanvraag</h2>
          <dl class="admin-details">
            <dt>Herkomst</dt>
            <dd>{{ booking.metadata.legacyImported
              ? "Geïmporteerd uit het oude onderwijssysteem"
              : "Online ingediend via Onderwijsformulier 2.0" }}</dd>
            <template v-if="booking.metadata.legacyImported">
              <dt>Oorspronkelijk aanvraagnummer</dt><dd>{{ display(booking.metadata.legacySourceId) }}</dd>
            </template>
          </dl>
        </section>
      </div>
    </template>
  </section>
</template>
