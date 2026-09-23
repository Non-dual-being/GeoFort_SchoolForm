<script setup lang="ts">
import { onBeforeUnmount, ref } from "vue";
import { RouterLink } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import { fetchRosterStaffCatalog } from "../services/dashboardRosterStaffApi";
import type { RosterStaffMember } from "../types/rosterStaff";

const items = ref<RosterStaffMember[]>([]);
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;

async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;

  loading.value = true;
  error.value = false;

  try {
    const result = await fetchRosterStaffCatalog(requestController.signal);
    items.value = result.items;
  } catch (requestError) {
    if (
      requestError instanceof DOMException
      && requestError.name === "AbortError"
    ) {
      return;
    }

    error.value = true;
  } finally {
    if (!requestController.signal.aborted) {
      loading.value = false;
    }
  }
}

void load();
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-roster-staff-config" aria-labelledby="staff-title">
    <header class="admin-rosters__intro">
      <div>
        <p class="admin-eyebrow">Roosterconfiguratie</p>
        <h1 id="staff-title">Personeel & vaardigheden</h1>
        <p>
          Centrale personeelsbron voor automatische roosterplanning. Vrijwilligers
          en betaalde krachten krijgen in de optimizer dezelfde werkurenweging.
        </p>
      </div>

      <RouterLink
        class="admin-button admin-button--secondary"
        :to="{ name: 'rosters' }"
      >
        Terug naar roosters
      </RouterLink>
    </header>

    <div v-if="error" class="admin-bookings__state" role="alert">
      <p>Personeelsconfiguratie kon niet worden geladen.</p>
      <AdminButton :loading="loading" @click="load">Opnieuw proberen</AdminButton>
    </div>

    <div v-else-if="loading && items.length === 0" class="admin-bookings__state">
      Personeel laden&hellip;
    </div>

    <div v-else class="admin-roster-staff-config__grid">
      <article
        v-for="member in items"
        :key="member.id"
        class="admin-card admin-roster-staff-config__member"
      >
        <header>
          <div>
            <h2>{{ member.name }}</h2>
            <span>{{ member.preferences.length }} modulevaardigheden</span>
          </div>

          <span
            class="admin-status"
            :class="member.isActive ? 'admin-status--confirmed' : 'admin-status--rejected'"
          >
            {{ member.isActive ? "Actief" : "Inactief" }}
          </span>
        </header>

        <div class="admin-roster-staff-config__badges">
          <span>{{ member.employmentType === "volunteer" ? "Vrijwilliger" : "Betaald" }}</span>
          <span v-if="member.canGuide">Begeleider</span>
          <span v-if="member.canCook">Kok mogelijk</span>
          <span v-if="!member.canGuide && member.canCook">Alleen kok</span>
        </div>

        <ol v-if="member.preferences.length" class="admin-roster-staff-config__preferences">
          <li
            v-for="preference in member.preferences"
            :key="`${preference.moduleKey}-${preference.rank}`"
          >
            <strong>{{ preference.rank }}</strong>
            <span>{{ preference.moduleLabel }}</span>
          </li>
        </ol>

        <p v-else class="admin-roster-staff-config__future">
          Geen lesmodules. Deze medewerker wordt alleen via een andere rol ingezet.
        </p>

        <p
          v-if="member.costRates.length === 0"
          class="admin-roster-staff-config__future"
        >
          Kosttarieven: nog niet ingevuld. Historische uurtarieven zijn al voorbereid.
        </p>
      </article>
    </div>
  </section>
</template>
