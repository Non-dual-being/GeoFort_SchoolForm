<script setup lang="ts">
import { nextTick, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { ArrowRight, Settings, Sunrise, Layers3 } from "lucide-vue-next";
import AdminButton from "../components/form/AdminButton.vue";
import ProgramDateCheck from "../components/configuration/ProgramDateCheck.vue";
import { useProgramConfiguration } from "../composables/useProgramConfiguration";
const { config, busy, error, load } = useProgramConfiguration();
const heading = ref<HTMLElement | null>(null);
onMounted(async () => { await load(); await nextTick(); heading.value?.focus(); });
</script>

<template>
  <div class="program-config">
    <header><p class="admin-eyebrow">Configuratie</p><h1 ref="heading" tabindex="-1"><Settings :size="28" aria-hidden="true" /> Onderwijsaanbod</h1><p>Bekijk en beheer wanneer scholen een ochtend- of vijfmoduleprogramma kunnen aanvragen.</p></header>
    <div v-if="error" class="program-config__error" role="alert"><p>{{ error }}</p><AdminButton variant="secondary" :disabled="busy" @click="load">Opnieuw laden</AdminButton></div>
    <p v-if="busy && !config" role="status">Instellingen laden…</p>
    <template v-if="config">
      <section aria-labelledby="manage-programs-heading"><h2 id="manage-programs-heading">Bekijken en beheren</h2>
        <div class="program-config__cards">
          <RouterLink class="program-config__entry" to="/configuratie/ochtend">
            <Sunrise :size="30" aria-hidden="true" /><h3>Ochtendprogramma</h3><p>Standaard op woensdag · 10:00–12:15</p><p class="program-config__help">Extra ochtenden of sluitingen per dag, maand of jaar.</p><span class="program-config__tag">{{ config.periods.filter(p => p.program === 'morning').length }} {{ config.periods.filter(p => p.program === 'morning').length === 1 ? 'periode' : 'periodes' }} ingesteld</span><span class="program-config__entry-action">Bekijken en beheren <ArrowRight :size="19" aria-hidden="true" /></span>
          </RouterLink>
          <RouterLink class="program-config__entry" to="/configuratie/vijf-modules">
            <Layers3 :size="30" aria-hidden="true" /><h3>Dagprogramma · vijf modules</h3><p>Standaard {{ config.fiveModuleOpen ? 'open' : 'gesloten' }}</p><p class="program-config__help">Stel het standaardaanbod en tijdelijke uitzonderingen in.</p><span class="program-config__tag">{{ config.periods.filter(p => p.program === 'five').length }} {{ config.periods.filter(p => p.program === 'five').length === 1 ? 'periode' : 'periodes' }} ingesteld</span><span class="program-config__entry-action">Bekijken en beheren <ArrowRight :size="19" aria-hidden="true" /></span>
          </RouterLink>
        </div>
      </section>
      <ProgramDateCheck @refreshed="config = $event" />
      <p class="program-config__help">Het viermoduleprogramma blijft beschikbaar op werkdagen vanaf 4 januari 2027. Bestaande aanvragen, prijzen en roosters blijven behouden wanneer je het aanbod wijzigt.</p>
    </template>
  </div>
</template>
