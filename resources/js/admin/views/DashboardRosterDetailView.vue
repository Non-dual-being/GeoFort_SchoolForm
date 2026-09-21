<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import { fetchDashboardRoster } from "../services/dashboardRosterApi";
import type { DashboardRosterPlan } from "../types/roster";
import { ApiError } from "../../services/http/apiClient";

const route = useRoute();
const plan = ref<DashboardRosterPlan | null>(null);
const loading = ref(false);
const notFound = ref(false);
const error = ref(false);
let controller: AbortController | undefined;

const planId = computed(() => {
  const value = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
  const id = Number(value);
  return typeof value === "string" && /^\d+$/.test(value) && Number.isSafeInteger(id) && id > 0
    ? id
    : null;
});

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

function statusLabel(status: string): string {
  if (status === "checked") return "Gecontroleerd";
  if (status === "published") return "Gepubliceerd";
  return "Concept";
}

async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  plan.value = null;
  notFound.value = planId.value === null;
  error.value = false;

  if (planId.value === null) return;

  loading.value = true;
  try {
    const result = await fetchDashboardRoster(planId.value, requestController.signal);
    plan.value = result.plan;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    notFound.value = requestError instanceof ApiError && [400, 404].includes(requestError.status);
    error.value = !notFound.value;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

watch(planId, () => { void load(); }, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-roster-detail" aria-labelledby="roster-detail-title">
    <RouterLink class="admin-booking-detail__back" :to="{ name: 'rosters' }">
      &larr; Terug naar roosters
    </RouterLink>

    <div v-if="loading" class="admin-bookings__state" role="status">
      <p>Rooster laden&hellip;</p>
    </div>

    <div v-else-if="notFound" class="admin-bookings__state">
      <h1 id="roster-detail-title">Rooster niet gevonden</h1>
      <RouterLink class="admin-button admin-button--secondary" :to="{ name: 'rosters' }">
      &larr; Terug naar roosters
      </RouterLink>
    </div>

    <div v-else-if="error" class="admin-bookings__state" role="alert">
      <p>Het rooster kon niet worden geladen.</p>
      <AdminButton @click="load">Opnieuw proberen</AdminButton>
    </div>

    <template v-else-if="plan">
      <header class="admin-roster-detail__header">
        <div>
          <p class="admin-eyebrow">Operationeel onderwijsrooster</p>
          <h1 id="roster-detail-title">{{ plan.school.name }}</h1>
          <p>
            {{ formatDate(plan.visitDate) }}
            &middot; {{ plan.education.programLabel }}
          </p>
        </div>
        <span class="admin-status admin-status--option">{{ statusLabel(plan.status) }}</span>
      </header>

      <div
        v-if="plan.needsReview"
        class="admin-inline-notice admin-inline-notice--warning"
        role="status"
      >
        De gekoppelde aanvraag is gewijzigd sinds dit rooster werd gestart.
        Controleer het rooster voordat het later wordt gepubliceerd.
      </div>

      <div class="admin-roster-detail__meta">
        <section class="admin-card">
          <h2>Bronaanvraag</h2>
          <dl class="admin-details">
            <dt>Aanvraag</dt>
            <dd>
              <RouterLink
                v-if="plan.bookingId"
                :to="{ name: 'booking-detail', params: { id: plan.bookingId } }"
              >
                #{{ plan.bookingId }}
              </RouterLink>
              <span v-else>Los rooster</span>
            </dd>
            <dt>Sector</dt><dd>{{ plan.education.sectorLabel }}</dd>
            <dt>Keuzemodule</dt><dd>{{ plan.education.choiceModuleLabel ?? "Niet van toepassing" }}</dd>
            <dt>Leerlingen</dt><dd>{{ plan.education.studentCount ?? "Onbekend" }}</dd>
            <dt>Revisie</dt><dd>{{ plan.revision }}</dd>
          </dl>
        </section>

        <section class="admin-card">
          <h2>Run 1</h2>
          <p>
            De operationele groepen zijn aangemaakt. In Run 2 koppelen we
            tijdvakken, modules, locaties en docenten aan deze groepen.
          </p>
        </section>
      </div>

      <section class="admin-card admin-roster-detail__groups-section">
        <h2>Operationele roostergroepen</h2>
        <p>
          Dit zijn roostergroepen, niet de onderwijsjaarselecties zoals groep 5 of HAVO 1.
        </p>

        <div class="admin-roster-groups">
          <article v-for="group in plan.groups" :key="group.id" class="admin-roster-group">
            <strong>{{ group.label }}</strong>
            <span>
              {{ group.studentCount === null ? "Leerlingverdeling nog niet vastgelegd" : `${group.studentCount} leerlingen` }}
            </span>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>