<script setup lang="ts">
import type { DashboardRosterPlan } from '../../types/roster';
import type { PairedStaffUse } from '../../services/pairedRosterStaff';
import RosterDaySchedule from './RosterDaySchedule.vue';
defineProps<{ plan: DashboardRosterPlan; staff: PairedStaffUse[] }>();
</script>
<template>
  <aside class="companion-preview" aria-label="Opgeslagen rooster van de andere school">
    <header><p class="admin-eyebrow">Al gepland · gekoppelde school</p><h3>{{ plan.school.name }}</h3><span>{{ plan.status === 'published' ? 'Definitief' : 'Concept' }} · {{ plan.planning.sessions.length }} lessen</span></header>
    <p>Deze opgeslagen indeling is het uitgangspunt. De wizard houdt rekening met bezette docenttijden en maximaal drie begeleide modules per persoon over beide scholen.</p>
    <div v-if="staff.length" class="companion-preview__staff">
      <article v-for="person in staff" :key="person.id"><strong>{{ person.name }}</strong><ul><li v-for="slot in person.slots" :key="slot.startTime + slot.moduleKey">{{ slot.startTime }}–{{ slot.endTime }} · {{ slot.cook ? 'Kok · ' : '' }}{{ slot.label }}</li></ul></article>
    </div>
    <p v-else>Bij deze school zijn nog geen docenten of kok ingeroosterd.</p>
    <details><summary>Bekijk het opgeslagen lesrooster</summary><RosterDaySchedule :groups="plan.groups" :sessions="plan.planning.sessions" :rounds="plan.planning.rounds" /></details>
  </aside>
</template>
<style scoped>
.companion-preview { background: var(--admin-surface-soft); border: 1px solid var(--admin-border); border-left: 4px solid var(--color-main-blue); border-radius: .8rem; padding: 1rem; display: grid; gap: .75rem; min-width: 0; }
.companion-preview h3, .companion-preview p { margin: 0; }
.companion-preview__staff { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:.65rem; }
.companion-preview article { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:.5rem; padding:.8rem; }
.companion-preview ul { list-style:none; padding:0; margin:.4rem 0 0; font-size:.85rem; }
.companion-preview summary { cursor:pointer; font-weight:600; }
.companion-preview details { min-width:0; overflow:auto; }
</style>
