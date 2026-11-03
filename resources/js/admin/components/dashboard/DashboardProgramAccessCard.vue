<script setup lang="ts">
import { RouterLink } from "vue-router";
import { Settings2 } from "lucide-vue-next";
</script>
<template>
  <section class="admin-card program-access" aria-labelledby="program-access-heading">
    <div><p class="admin-eyebrow">Onderwijsaanbod</p><h2 id="program-access-heading">Programma’s openstellen</h2><p>Beheer extra ochtenden en de beschikbaarheid van het vijfmoduleprogramma.</p></div>
    <RouterLink to="/configuratie" class="program-access__link"><Settings2 :size="18" aria-hidden="true" /> Configuratie openen</RouterLink>
  </section>
</template>
<style scoped>
.program-access { margin-block:1.5rem; padding:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; }
.program-access__link { display:inline-flex; align-items:center; gap:.5rem; padding:.8rem 1rem; background:#edf4ff; border:1px solid #c5d5ed; border-radius:.6rem; color:#173b71; font-weight:700; text-decoration:none; }
</style>
