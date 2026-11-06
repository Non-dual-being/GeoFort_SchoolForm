<script setup lang="ts">
defineProps<{letter?:string;name:string;approved?:boolean}>();
</script>
<template>
  <span class="roster-staff-letter" :class="{'roster-staff-letter--approved':approved}" :title="`${name}${approved?' · Goedgekeurd · vast':''}`" :aria-label="`${letter??'?'} · ${name}${approved?' · Goedgekeurd · vast':''}`">{{letter??'?'}}</span>
</template>
<style scoped>
.roster-staff-letter{display:inline-grid;place-items:center;flex-shrink:0;box-sizing:border-box;min-width:2rem;height:2rem;padding:0 .3rem;border:1px solid #b3c4df;border-radius:.35rem;background:#ffffffd9;color:#173d70;font-size:var(--roster-letter-size,1rem);line-height:1;font-weight:700;vertical-align:middle}
.roster-staff-letter--approved{background:#164e80;color:#fff;border:2px solid #164e80;box-shadow:0 0 0 2px #d2e7fa}
</style>
