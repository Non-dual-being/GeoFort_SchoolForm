<script setup lang="ts">
import type { RosterValidationResult } from "../../types/roster";
defineProps<{ result: RosterValidationResult | null; loading: boolean; finalized: boolean }>();
</script>

<template>
  <section class="admin-roster-validation" aria-labelledby="roster-validation-title">
    <h2 id="roster-validation-title">Roostercontrole</h2>
    <p v-if="loading" role="status">De server controleert het actuele rooster…</p>
    <p v-else-if="finalized">Deze versie is gecontroleerd en definitief vastgelegd.</p>
    <p v-else-if="!result">Gebruik Rooster controleren om de actuele revisie te laten controleren.</p>
    <template v-if="result">
      <p>Gecontroleerde revisie: {{ result.revision }}</p>
      <div v-if="result.errors.length" class="admin-inline-error" role="alert">
        <strong>{{ result.errors.length }} blokkerende fouten</strong>
        <ul><li v-for="(issue, index) in result.errors" :key="index">{{ issue.message }}</li></ul>
      </div>
      <p v-else class="admin-inline-notice">Geen blokkerende fouten. Het rooster kan definitief worden gemaakt.</p>
      <div v-if="result.warnings.length" class="admin-inline-notice admin-inline-notice--warning">
        <strong>Aandachtspunten (blokkeren niet)</strong>
        <ul><li v-for="(issue, index) in result.warnings" :key="index">{{ issue.message }}</li></ul>
      </div>
    </template>
  </section>
</template>
