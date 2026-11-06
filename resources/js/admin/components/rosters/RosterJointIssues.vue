<script setup lang="ts">
import {computed} from 'vue';
import type {DashboardRosterPlan} from '../../types/roster';
import {presentJointIssue,type JointDisplayIssue} from '../../services/rosterJointIssuePresentation';
const props=defineProps<{issues:JointDisplayIssue[];plans:DashboardRosterPlan[];allowOpen?:boolean}>();
const emit=defineEmits<{openLesson:[planId:number,sessionId:number,groupId:number]}>();
const rows=computed(()=>props.issues.map(issue=>({issue,...presentJointIssue(issue,props.plans)})));
</script>
<template>
  <ul v-if="rows.length" class="joint-issues" aria-label="Aandachtspunten per school en module">
    <li v-for="(row,index) in rows" :key="index" class="joint-issues__item">
      <div class="joint-issues__tags">
        <span v-for="school in row.schools" :key="school" class="joint-issues__tag joint-issues__tag--school" :aria-label="`School: ${school}`">{{school}}</span>
        <span v-for="module in row.modules" :key="module" class="joint-issues__tag" :aria-label="`Module: ${module}`">{{module}}</span>
        <span class="joint-issues__tag joint-issues__tag--type" :aria-label="`Soort fout: ${row.category}`">{{row.category}}</span>
      </div>
      <p v-if="row.context.length" class="joint-issues__context">{{row.context.join(' · ')}}</p>
      <p class="joint-issues__message">{{row.issue.message}}</p>
      <button v-if="row.target&&allowOpen" type="button" class="joint-issues__action" @click="emit('openLesson',row.target.planId,row.target.sessionId,row.target.groupId)">Bekijk les</button>
    </li>
  </ul>
</template>
<style scoped>
.joint-issues{display:grid;gap:.7rem;padding:0;margin:.9rem 0;list-style:none}.joint-issues__item{display:grid;gap:.55rem;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:.6rem;padding:.9rem 1rem;min-width:0}.joint-issues__tags{display:flex;flex-wrap:wrap;gap:.4rem}.joint-issues__tag{display:inline-flex;max-width:100%;padding:.25rem .55rem;border:1px solid #d5deec;border-radius:.4rem;background:#f5f7fb;color:#344d70;font-size:.75rem;font-weight:600;line-height:1.4;overflow-wrap:anywhere}.joint-issues__tag--school{background:#eaf2fd;border-color:#c3d6f1;color:#1b487c}.joint-issues__tag--type{background:#fff6ef;border-color:#e9d2b5;color:#73502c}.joint-issues p{margin:0}.joint-issues__context{font-size:.78rem;color:#536582}.joint-issues__message{font-size:.88rem;line-height:1.6;max-width:105ch;overflow-wrap:anywhere}.joint-issues__action{justify-self:start;font:inherit;font-size:.8rem;background:var(--admin-surface);color:#16345f;padding:.4rem .7rem;border:1px solid #becde3;border-radius:.4rem;cursor:pointer}.joint-issues__action:hover{border-color:#668cbd;box-shadow:0 0 0 2px #c9dcf4}.joint-issues__action:focus-visible{outline:3px solid #759cdf;outline-offset:3px}
</style>
