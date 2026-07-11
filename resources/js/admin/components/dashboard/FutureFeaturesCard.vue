<script setup lang="ts">
const futureFeatures = [
  "Aanvragen per week bekijken",
  "Aanvragen in optie beoordelen",
  "Status goedkeuren of afwijzen",
  "Leerlingenaantallen beheren",
  "Datums blokkeren",
  "Gegevens downloaden",
];
</script>

<template>
  <article class="admin-card">
    <h2>Toekomstig aanvragenbeheer</h2>
    <p>Deze beheerfuncties worden in volgende stappen modulair toegevoegd.</p>
    <ul class="admin-roadmap-list">
      <li v-for="feature in futureFeatures" :key="feature">{{ feature }}</li>
    </ul>
  </article>
</template>
