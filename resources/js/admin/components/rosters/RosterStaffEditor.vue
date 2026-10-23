<script setup lang="ts">
import AdminSelect from "../form/AdminSelect.vue";
import { computed, inject, nextTick, ref, watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminDialog from "../feedback/AdminDialog.vue";
import RosterStaffHistory from "./RosterStaffHistory.vue";
import { createRosterStaff, deactivateRosterStaff, previewRosterStaffImpact, updateRosterStaff } from "../../services/dashboardRosterStaffApi";
import { nextStaffPreferenceRank, sameStaffPreferences, staffPreferenceErrors } from "../../services/rosterStaffPreferences";
import { adminBootstrapKey } from "../../types/admin";
import { ApiError } from "../../../services/http/apiClient";
import type { RosterStaffDetail, RosterStaffImpactPreview, RosterStaffModule, RosterStaffValues } from "../../types/rosterStaff";

const props = defineProps<{ detail: RosterStaffDetail | null; modules: RosterStaffModule[]; catalogRevision: number }>();
const emit = defineEmits<{ saved: [result: RosterStaffDetail]; cancel: []; reload: [] }>();
const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const tokens = { create: bootstrap.rosterStaffCreateCsrfToken, update: bootstrap.rosterStaffUpdateCsrfToken, preview: bootstrap.rosterStaffImpactCsrfToken, deactivate: bootstrap.rosterStaffDeactivateCsrfToken };
const member = props.detail?.member;
const initial: RosterStaffValues = {
  name: member?.name ?? "", isActive: member?.isActive ?? true,
  employmentType: member?.employmentType === "volunteer" ? "volunteer" : "paid",
  canGuide: member?.canGuide ?? true, canCook: member?.canCook ?? false,
  preferences: (member?.preferences ?? []).filter(p => !p.isLegacy).map(p => ({ moduleKey: p.moduleKey, rank: p.rank })),
};
const draft = ref<RosterStaffValues>(structuredClone(initial));
const legacy = ref((member?.preferences ?? []).filter(p => p.isLegacy).map(p => ({ ...p })));
const removeLegacy = ref<string[]>([]);
const allPreferences = computed(() => [...draft.value.preferences, ...legacy.value.filter(p => !removeLegacy.value.includes(p.moduleKey))]);
const preferencesChanged = computed(() => !member || !sameStaffPreferences(member.preferences, allPreferences.value));
const legacyDuplicates = computed(() => !!member && Object.keys(staffPreferenceErrors(member.preferences)).length > 0);
const rankErrors = computed(() => preferencesChanged.value ? staffPreferenceErrors(allPreferences.value) : {});
const invalidRanks = computed(() => Object.keys(rankErrors.value).length > 0);
const dirty = computed(() => JSON.stringify(draft.value) !== JSON.stringify(initial) || preferencesChanged.value);
const busy = ref(false);
const error = ref<string | null>(null);
const conflict = ref(false);
const rankLimit = ref<string | null>(null);
const impact = ref<RosterStaffImpactPreview | null>(null);
const deactivation = ref<RosterStaffImpactPreview | null>(null);
const preparingDeactivation = ref(false);
const deactivateButton = ref<HTMLDivElement | null>(null);
watch([draft, legacy, removeLegacy], () => { impact.value = null; rankLimit.value = null; }, { deep: true });

function skill(key: string) { return draft.value.preferences.find(p => p.moduleKey === key); }
function setSkillRank(key: string, event: Event): void {
  const preference = skill(key);
  if (preference) preference.rank = (event.target as HTMLInputElement).valueAsNumber;
}
function toggleSkill(key: string, event: Event): void {
  if (skill(key)) { draft.value.preferences = draft.value.preferences.filter(p => p.moduleKey !== key); return; }
  const rank = nextStaffPreferenceRank(allPreferences.value);
  if (rank === null) {
    rankLimit.value = Math.max(...allPreferences.value.map(p => Number(p.rank))) >= 255
      ? "De hoogste rang is 255 of hoger. Verlaag eerst zelf een rang voordat je een module toevoegt; rangen worden niet automatisch aangepast."
      : "Corrigeer eerst de bestaande rangen. Een nieuwe module moet een gehele rang van 1 tot en met 255 krijgen.";
    (event.target as HTMLInputElement).checked = false;
    return;
  }
  draft.value.preferences.push({ moduleKey: key, rank });
}
function payload() {
  return {
    ...draft.value, expectedCatalogRevision: props.detail?.catalogRevision ?? props.catalogRevision,
    ...(member ? { staffId: member.id, expectedVersion: member.version, removeLegacyModuleKeys: removeLegacy.value,
      legacyPreferenceRanks: legacy.value.filter(p => !removeLegacy.value.includes(p.moduleKey) && member.preferences.find(old => old.moduleKey === p.moduleKey)?.rank !== p.rank).map(p => ({ moduleKey: p.moduleKey, rank: p.rank })),
    } : {}),
  };
}
function failure(cause: unknown): void {
  impact.value = null;
  conflict.value = cause instanceof ApiError && cause.status === 409;
  error.value = cause instanceof ApiError && cause.status === 401
    ? "Je sessie is verlopen. Log opnieuw in; je invoer blijft hier staan."
    : cause instanceof Error ? cause.message : "Opslaan is niet gelukt. Je invoer is behouden.";
}
async function check(): Promise<void> {
  if (busy.value || invalidRanks.value) return;
  busy.value = true; error.value = null; conflict.value = false; impact.value = null;
  try {
    if (!member) { emit("saved", await createRosterStaff(payload(), tokens.create)); return; }
    const preview = await previewRosterStaffImpact(payload(), tokens.preview);
    if (preview.impact.length > 0 || removeLegacy.value.length > 0) impact.value = preview;
    else emit("saved", await updateRosterStaff({ ...payload(), impactToken: preview.impactToken }, tokens.update));
  } catch (cause) { failure(cause); }
  finally { busy.value = false; }
}
async function confirm(): Promise<void> {
  if (busy.value || !impact.value || invalidRanks.value) return;
  busy.value = true; error.value = null;
  try { emit("saved", await updateRosterStaff({ ...payload(), impactToken: impact.value.impactToken }, tokens.update)); }
  catch (cause) { failure(cause); }
  finally { busy.value = false; }
}
async function prepareDeactivation(): Promise<void> {
  if (busy.value || dirty.value || !member) return;
  busy.value = true; error.value = null; conflict.value = false; impact.value = null;
  preparingDeactivation.value = true;
  try {
    const preview = await previewRosterStaffImpact({ ...initial, isActive: false, staffId: member.id, expectedVersion: member.version, expectedCatalogRevision: props.detail!.catalogRevision }, tokens.preview);
    busy.value = false;
    preparingDeactivation.value = false;
    await nextTick();
    // Restore the trigger after loading so the shared dialog can return focus to it on Escape.
    deactivateButton.value?.querySelector("button")?.focus();
    deactivation.value = preview;
  } catch (cause) { failure(cause); }
  finally { busy.value = false; preparingDeactivation.value = false; }
}
async function closeDeactivation(): Promise<void> {
  if (busy.value) return;
  deactivation.value = null;
  await nextTick();
  deactivateButton.value?.querySelector("button")?.focus();
}
async function confirmDeactivation(): Promise<void> {
  if (busy.value || !deactivation.value || !member) return;
  busy.value = true; error.value = null;
  try {
    emit("saved", await deactivateRosterStaff({ staffId: member.id, expectedVersion: member.version, expectedCatalogRevision: props.detail!.catalogRevision, impactToken: deactivation.value.impactToken }, tokens.deactivate));
  } catch (cause) {
    failure(cause);
    deactivation.value = null;
    await nextTick();
    document.getElementById("staff-save-error")?.focus();
  } finally { busy.value = false; }
}
</script>

<template>
  <section class="admin-card admin-roster-staff-editor" aria-labelledby="staff-editor-title">
    <header class="admin-roster-editor__heading">
      <div>
        <p class="admin-eyebrow">{{ member ? 'Medewerker #' + member.id + ' · versie ' + member.version : 'Nieuwe medewerker' }}</p>
        <h2 id="staff-editor-title" tabindex="-1">{{ member ? "Medewerker wijzigen" : "Medewerker toevoegen" }}</h2>
        <p>Wijzigingen gelden voor de centrale catalogus. Definitieve roosters blijven bewaard zoals vastgesteld.</p>
      </div>
      <AdminButton variant="ghost" :disabled="busy" @click="emit('cancel')">Sluiten</AdminButton>
    </header>
    <form @submit.prevent="check">
      <fieldset :disabled="busy" class="admin-roster-staff-editor__fields">
        <div class="admin-roster-staff-editor__basics">
          <label class="admin-field">
            <span class="admin-field__label">Weergavenaam</span>
            <input v-model="draft.name" class="admin-control admin-text-input" name="staffName" maxlength="120" required autocomplete="off">
          </label>
          <AdminSelect v-model="draft.employmentType" label="Dienstverband" name="employmentType" :allow-empty="false" :options="[{value:'paid',label:'Betaald'},{value:'volunteer',label:'Vrijwilliger'}]" />
        </div>
        <div class="admin-roster-staff-editor__roles">
          <label><input v-model="draft.isActive" name="isActive" type="checkbox"> Actief</label>
          <label><input v-model="draft.canGuide" name="canGuide" type="checkbox"> Kan begeleiden</label>
          <label><input v-model="draft.canCook" name="canCook" type="checkbox"> Vaardigheid: kok (Voedsel Innovatie)</label>
        </div>
        <p class="admin-field__help">Inactieve medewerkers zijn niet beschikbaar voor nieuwe planning. Bestaande concepttoewijzingen moeten worden opgelost voordat het rooster definitief kan worden.</p>
        <p class="admin-field__help">Een kok kan ook begeleiden met onderstaande vaardigheden, maar nooit tegelijk tijdens Voedsel Innovatie. Kies alleen de kokvaardigheid voor iemand die uitsluitend kookt.</p>
        <h3>Modulevaardigheden en voorkeur</h3>
        <p id="staff-rank-help" class="admin-field__help">Een lager getal heeft meer voorkeur. Gebruik per medewerker unieke gehele rangen van 1 tot en met 255. Een nieuwe module krijgt de hoogste rang + 1. Vaardigheden blijven bewaard als begeleiden uit staat.</p>
        <p v-if="legacyDuplicates" class="admin-inline-notice">Deze medewerker heeft bestaande dubbele rangen. Die blijven behouden bij gewone persoonsgegevenswijzigingen. Zodra je de modulevoorkeuren wijzigt, moet elke geselecteerde module een unieke rang krijgen.</p>
        <p v-if="rankLimit" class="admin-inline-error" role="alert">{{ rankLimit }}</p>
        <p v-if="invalidRanks" class="admin-inline-error" role="alert">Controleer de gemarkeerde voorkeursrangen. Opslaan is pas mogelijk als alle geselecteerde modules een unieke gehele rang van 1 tot en met 255 hebben.</p>
        <div class="admin-roster-staff-editor__skills">
          <div v-for="(module, index) in modules" :key="module.moduleKey" class="admin-roster-staff-editor__skill" :class="{ 'is-selected': !!skill(module.moduleKey) }">
            <label class="admin-roster-staff-editor__choice"><input type="checkbox" :name="'skill-' + module.moduleKey" :checked="!!skill(module.moduleKey)" :aria-describedby="!skill(module.moduleKey) ? 'staff-rank-disabled-' + index : 'staff-rank-help'" @change="toggleSkill(module.moduleKey, $event)"><span>{{ module.moduleLabel }}</span></label>
            <label class="admin-field admin-roster-staff-editor__rank" :class="{ 'is-disabled': !skill(module.moduleKey) }" :title="!skill(module.moduleKey) ? 'Vink deze module aan om een voorkeursrang in te stellen.' : undefined">
              <span class="admin-field__label">Voorkeursrang</span>
              <input :value="Number.isFinite(skill(module.moduleKey)?.rank) ? skill(module.moduleKey)?.rank : ''" :disabled="!skill(module.moduleKey)" class="admin-control admin-text-input" type="number" min="1" max="255" step="1" required :aria-label="'Voorkeursrang ' + module.moduleLabel" :aria-invalid="!!rankErrors[module.moduleKey]" :aria-describedby="!skill(module.moduleKey) ? 'staff-rank-disabled-' + index : rankErrors[module.moduleKey] ? 'staff-rank-error-' + index : 'staff-rank-help'" @input="setSkillRank(module.moduleKey, $event)">
            </label>
            <p v-if="!skill(module.moduleKey)" :id="'staff-rank-disabled-' + index" class="admin-field__help admin-roster-staff-editor__rank-help">Vink deze module aan om een voorkeursrang in te stellen.</p>
            <p v-if="rankErrors[module.moduleKey]" :id="'staff-rank-error-' + index" class="admin-field__error">{{ rankErrors[module.moduleKey] }}</p>
          </div>
        </div>
        <section v-if="legacy.length" class="admin-roster-staff-editor__legacy">
          <h3>Legacy / onbekende modules</h3>
          <p>Deze vaardigheden blijven bewaard. Je kunt hun rang bewust aanpassen of ze expliciet verwijderen.</p>
          <div class="admin-roster-staff-editor__skills">
            <div v-for="(preference, index) in legacy" :key="preference.moduleKey" class="admin-roster-staff-editor__skill" :class="{ 'is-selected': !removeLegacy.includes(preference.moduleKey) }">
              <label class="admin-roster-staff-editor__choice"><input v-model="removeLegacy" type="checkbox" :value="preference.moduleKey"><span>Expliciet verwijderen: {{ preference.moduleLabel }}</span></label>
              <label class="admin-field admin-roster-staff-editor__rank" :class="{ 'is-disabled': removeLegacy.includes(preference.moduleKey) }" :title="removeLegacy.includes(preference.moduleKey) ? 'Maak de verwijdering ongedaan om deze voorkeursrang te wijzigen.' : undefined">
                <span class="admin-field__label">Voorkeursrang</span>
                <input v-if="!removeLegacy.includes(preference.moduleKey)" v-model.number="preference.rank" class="admin-control admin-text-input" type="number" min="1" max="255" step="1" required :aria-label="'Voorkeursrang legacy ' + preference.moduleLabel" :aria-invalid="!!rankErrors[preference.moduleKey]" :aria-describedby="rankErrors[preference.moduleKey] ? 'staff-legacy-rank-error-' + index : 'staff-rank-help'">
                <input v-else value="" disabled class="admin-control admin-text-input" type="number" :aria-label="'Voorkeursrang legacy ' + preference.moduleLabel" :aria-describedby="'staff-legacy-disabled-' + index">
              </label>
              <p v-if="removeLegacy.includes(preference.moduleKey)" :id="'staff-legacy-disabled-' + index" class="admin-field__help admin-roster-staff-editor__rank-help">Maak de verwijdering ongedaan om deze voorkeursrang te wijzigen.</p>
              <p v-if="rankErrors[preference.moduleKey]" :id="'staff-legacy-rank-error-' + index" class="admin-field__error">{{ rankErrors[preference.moduleKey] }}</p>
            </div>
          </div>
        </section>
      </fieldset>
      <p v-if="error" id="staff-save-error" tabindex="-1" class="admin-inline-error" role="alert">{{ error }}</p>
      <AdminButton v-if="conflict" variant="secondary" :disabled="busy" @click="emit('reload')">Actuele gegevens laden (invoer vervangen)</AdminButton>
      <section v-if="impact" class="admin-inline-notice admin-roster-staff-editor__impact" aria-labelledby="staff-impact-title">
        <h3 id="staff-impact-title">Gevolgen vóór opslag</h3>
        <p v-if="impact.impact.length">Deze conceptroosters raken ongeldig. Toewijzingen blijven staan en blokkeren finalisatie totdat ze zijn opgelost.</p>
        <ul>
          <li v-for="item in impact.impact" :key="item.planId">
            <a :href="'#/roosters/' + item.planId" target="_blank" rel="noopener noreferrer">Rooster #{{ item.planId }} · {{ item.visitDate }}</a>
            <p v-for="reason in item.reasons" :key="reason">{{ reason }}</p>
          </li>
          <li v-for="key in removeLegacy" :key="key">Legacyvaardigheid verwijderen: {{ key }}</li>
        </ul>
        <p>Definitieve snapshots blijven ongewijzigd. De server controleert de versies opnieuw bij bevestiging.</p>
        <AdminButton :loading="busy" :disabled="invalidRanks" @click="confirm">Gevolgen bevestigen en opslaan</AdminButton>
      </section>
      <div class="admin-roster-staff-editor__actions">
        <AdminButton v-if="!impact" type="submit" :loading="busy" :disabled="invalidRanks">{{ member ? "Wijziging controleren" : "Medewerker toevoegen" }}</AdminButton>
        <AdminButton variant="secondary" :disabled="busy" @click="emit('cancel')">Annuleren</AdminButton>
      </div>
    </form>
    <RosterStaffHistory v-if="detail" :items="detail.history" />
    <section v-if="member" class="admin-roster-staff-editor__deactivate" aria-labelledby="staff-deactivate-title">
      <h3 id="staff-deactivate-title">Beschikbaarheid beëindigen</h3>
      <p v-if="member.isActive">Deactiveer deze medewerker om nieuwe inzet te stoppen. Roosterhistorie blijft behouden en opnieuw activeren blijft mogelijk.</p>
      <p v-else>Deze medewerker is inactief. Vink Actief aan en sla op om nieuwe inzet weer mogelijk te maken.</p>
      <p v-if="member.isActive && dirty" id="staff-deactivate-help" class="admin-field__help">Sla je openstaande wijzigingen eerst op of sluit de editor zonder op te slaan.</p>
      <div v-if="member.isActive" ref="deactivateButton">
        <AdminButton variant="danger" :loading="preparingDeactivation" :disabled="busy || dirty" :aria-describedby="dirty ? 'staff-deactivate-help' : undefined" @click="prepareDeactivation">Medewerker deactiveren</AdminButton>
      </div>
    </section>
    <AdminDialog :open="!!deactivation" title="Medewerker deactiveren?" :close-disabled="busy" :closedby="busy ? 'none' : undefined" @close="closeDeactivation">
      <p>{{ member?.name }} verdwijnt uit nieuwe planning. De roosterhistorie blijft behouden. Je kunt deze medewerker later opnieuw activeren.</p>
      <section v-if="deactivation?.impact.length" class="admin-roster-staff-editor__impact">
        <h3>Gevolgen voor conceptroosters</h3>
        <p>Deze toewijzingen blijven staan en moeten worden opgelost voordat finalisatie mogelijk is:</p>
        <ul><li v-for="item in deactivation.impact" :key="item.planId"><a :href="'#/roosters/' + item.planId" target="_blank" rel="noopener noreferrer">Rooster #{{ item.planId }} · {{ item.visitDate }}</a><p v-for="reason in item.reasons" :key="reason">{{ reason }}</p></li></ul>
      </section>
      <p v-else>Er zijn geen geraakte conceptroosters gevonden.</p>
      <template #footer>
        <AdminButton variant="secondary" autofocus :disabled="busy" @click="closeDeactivation">Annuleren</AdminButton>
        <AdminButton variant="danger" :loading="busy" @click="confirmDeactivation">Medewerker deactiveren</AdminButton>
      </template>
    </AdminDialog>
  </section>
</template>
