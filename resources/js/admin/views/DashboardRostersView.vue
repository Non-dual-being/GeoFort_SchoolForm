<script setup lang="ts">
import { onBeforeUnmount, ref } from "vue";
import { RouterLink } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import { fetchDashboardRosters } from "../services/dashboardRosterApi";
import type { DashboardRosterPlanSummary } from "../types/roster";

const items = ref<DashboardRosterPlanSummary[]>([]);
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;

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
  loading.value = true;
  error.value = false;

  try {
    const result = await fetchDashboardRosters(requestController.signal);
    items.value = result.items;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    error.value = true;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

void load();
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-rosters" aria-labelledby="rosters-title">
    <header class="admin-rosters__intro">
      <div>
        <p class="admin-eyebrow">Onderwijsplanning</p>
        <h1 id="rosters-title">Roosters</h1>
        <p>
          Operationele roosters worden vanuit bestaande onderwijsaanvragen gestart.
          De les- en docentenplanning volgt in de volgende ontwikkelrun.
        </p>
      </div>

      <RouterLink class="admin-button admin-button--secondary" to="/aanvragen">
        Naar aanvragen
      </RouterLink>
    </header>

    <div v-if="error" class="admin-bookings__state" role="alert">
      <p>De roosters konden niet worden geladen.</p>
      <AdminButton :loading="loading" @click="load">Opnieuw proberen</AdminButton>
    </div>

    <div v-else-if="loading && items.length === 0" class="admin-bookings__state" role="status">
      <p>Roosters laden&hellip;</p>
    </div>

    <div v-else-if="items.length === 0" class="admin-bookings__state">
      <p>
        Er zijn nog geen webroosters. Open een aanvraag en kies
        <strong>Rooster maken / openen</strong>.
      </p>
    </div>

    <div v-else class="admin-bookings-table-wrap">
      <table class="admin-bookings-table">
        <thead>
          <tr>
            <th scope="col">Status</th>
            <th scope="col">Bezoekdatum</th>
            <th scope="col">School</th>
            <th scope="col">Programma</th>
            <th scope="col">Leerlingen</th>
            <th scope="col">Roostergroepen</th>
            <th scope="col">Openen</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ statusLabel(item.status) }}</td>
            <td>{{ formatDate(item.visitDate) }}</td>
            <td>
              <strong>{{ item.schoolName }}</strong>
              <span v-if="item.city"> &middot; {{ item.city }}</span>
            </td>
            <td>
              {{ item.programLabel }}
              <template v-if="item.moduleLabel"> &middot; {{ item.moduleLabel }}</template>
            </td>
            <td>{{ item.studentCount ?? "Onbekend" }}</td>
            <td>{{ item.groupCount }}</td>
            <td>
              <RouterLink
                class="admin-bookings__view-link"
                :to="{ name: 'roster-detail', params: { id: item.id } }"
              >
                Openen <span aria-hidden="true">&rarr;</span>
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>