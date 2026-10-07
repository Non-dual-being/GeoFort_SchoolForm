<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from "vue";
import { Search, X } from "lucide-vue-next";
import { RouterLink } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import { fetchRosterStaffCatalog, fetchRosterStaff } from "../services/dashboardRosterStaffApi";
import type { RosterStaffMember, RosterStaffModule, RosterStaffDetail } from "../types/rosterStaff";
import RosterStaffEditor from "../components/rosters/RosterStaffEditor.vue";
import RosterStaffModuleList from "../components/rosters/RosterStaffModuleList.vue";

const items = ref<RosterStaffMember[]>([]);
const loading = ref(false);
const error = ref(false);
const editorOpen = ref(false);
const detail = ref<RosterStaffDetail | null>(null);
const modules = ref<RosterStaffModule[]>([]);
const catalogRevision = ref(0);
const editorKey = ref(0);
const editorError = ref<string | null>(null);
const opening = ref(false);
const success = ref<string | null>(null);
const search = ref("");
const filteredItems = computed(() => items.value.filter(member => member.name.toLocaleLowerCase("nl").includes(search.value.trim().toLocaleLowerCase("nl"))));
let returnFocusId = "staff-add";
let successTimer: ReturnType<typeof setTimeout> | undefined;

async function edit(id?: number): Promise<void> {
  returnFocusId = id ? `staff-edit-${id}` : "staff-add";
  opening.value = true; editorError.value = null;
  try {
    detail.value = id ? await fetchRosterStaff(id) : null;
    editorKey.value++; editorOpen.value = true;
    await nextTick();
    document.getElementById("staff-editor-title")?.focus();
  } catch (cause) { editorError.value = cause instanceof Error ? cause.message : "Medewerker kon niet worden geladen."; }
  finally { opening.value = false; }
}
async function closeEditor(): Promise<void> {
  editorOpen.value = false;
  await nextTick();
  document.getElementById(returnFocusId)?.focus();
}
async function saved(result: RosterStaffDetail): Promise<void> {
  editorOpen.value = false; success.value = "Personeelswijziging opgeslagen.";
  clearTimeout(successTimer);
  successTimer = setTimeout(() => { success.value = null; }, 5000);
  await load();
  if (!filteredItems.value.some(member => member.id === result.member.id)) search.value = "";
  await nextTick();
  document.getElementById(`staff-edit-${result.member.id}`)?.focus();
}
async function clearSearch(): Promise<void> {
  search.value = "";
  await nextTick();
  document.getElementById("staff-search")?.focus();
}
async function reloadEditor(): Promise<void> {
  const id = detail.value?.member.id;
  await load();
  await edit(id);
}
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
    modules.value = result.modules;
    catalogRevision.value = result.catalogRevision;
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
onBeforeUnmount(() => { controller?.abort(); clearTimeout(successTimer); });
</script>

<template>
  <section class="admin-roster-staff-config" aria-labelledby="staff-title">
    <header class="admin-rosters__intro">
      <div>
        <p class="admin-eyebrow">Roosterconfiguratie</p>
        <h1 id="staff-title">Personeel & vaardigheden</h1>
        <p>
          Beheer de centrale personeelsbron voor automatische en handmatige planning.
          Deactiveer medewerkers om hun roosterhistorie te behouden.
        </p>
      </div>

      <RouterLink
        class="admin-button admin-button--secondary"
        :to="{ name: 'rosters' }"
      >
        Terug naar roosters
      </RouterLink>
    </header>

    <p v-if="success" class="admin-inline-notice" role="status">{{ success }}</p>
    <p v-if="editorError" class="admin-inline-error" role="alert">{{ editorError }}</p>
    <section v-if="!editorOpen" class="admin-roster-staff-config__toolbar" aria-label="Personeel beheren">
      <AdminButton id="staff-add" :disabled="loading || opening || !catalogRevision" @click="edit()">Medewerker toevoegen</AdminButton>
      <div class="admin-roster-staff-config__search">
        <label class="admin-field__label" for="staff-search">Zoeken op medewerker</label>
        <div class="admin-roster-staff-config__search-control">
          <Search :size="20" aria-hidden="true" />
          <input id="staff-search" v-model="search" type="search" class="admin-control admin-text-input" autocomplete="off" placeholder="Weergavenaam" aria-describedby="staff-result-count" @keydown.esc.prevent="clearSearch">
          <AdminButton v-if="search" variant="ghost" aria-label="Zoekterm wissen" @click="clearSearch"><X :size="18" aria-hidden="true" /><span>Wissen</span></AdminButton>
        </div>
        <p id="staff-result-count" class="admin-field__help" role="status">{{ filteredItems.length }} van {{ items.length }} medewerkers</p>
      </div>
    </section>
    <RosterStaffEditor v-if="editorOpen" :key="editorKey" :detail="detail" :modules="modules" :catalog-revision="catalogRevision" @saved="saved" @cancel="closeEditor" @reload="reloadEditor" />

    <div v-if="error" class="admin-bookings__state" role="alert">
      <p>Personeelsconfiguratie kon niet worden geladen.</p>
      <AdminButton :loading="loading" @click="load">Opnieuw proberen</AdminButton>
    </div>

    <div v-else-if="loading && items.length === 0" class="admin-bookings__state">
      Personeel laden&hellip;
    </div>

    <div v-else-if="!editorOpen && !filteredItems.length" class="admin-bookings__state">
      <p>{{ search ? 'Geen medewerkers gevonden voor “' + search + '”.' : 'Er zijn nog geen medewerkers.' }}</p>
      <AdminButton v-if="search" variant="secondary" @click="clearSearch">Zoekterm wissen</AdminButton>
    </div>
    <div v-else-if="!editorOpen" class="admin-roster-staff-config__grid">
      <article
        v-for="member in filteredItems"
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
            :class="member.isActive ? 'admin-status--confirmed' : ''"
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

        <RosterStaffModuleList v-if="member.preferences.length" :preferences="member.preferences" :member-name="member.name" />

        <p v-else class="admin-roster-staff-config__future">
          Geen lesmodules. Deze medewerker wordt alleen via een andere rol ingezet.
        </p>

        <footer class="admin-roster-staff-config__footer">
        <p
          v-if="member.costRates.length === 0"
          class="admin-roster-staff-config__future"
        >
          Kosttarieven: nog niet ingevuld. Historische uurtarieven zijn al voorbereid.
        </p>
        <AdminButton :id="'staff-edit-' + member.id" variant="secondary" :disabled="opening" :aria-label="'Wijzig ' + member.name" @click="edit(member.id)">Wijzigen</AdminButton>
        </footer>
      </article>
    </div>
  </section>
</template>
