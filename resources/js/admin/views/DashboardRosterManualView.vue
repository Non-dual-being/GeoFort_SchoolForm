<script setup lang="ts">
import {inject,onBeforeUnmount,ref,watch} from 'vue';
import {RouterLink,useRoute,useRouter} from 'vue-router';
import AdminButton from '../components/form/AdminButton.vue';
import RosterJointWorkspace from '../components/rosters/RosterJointWorkspace.vue';
import RosterJointIssues from '../components/rosters/RosterJointIssues.vue';
import {fetchDashboardRoster} from '../services/dashboardRosterApi';
import {jointWorkspace,type JointLoad} from '../services/dashboardRosterWorkspaceApi';
import {linkedWorkspaceLocation} from '../services/rosterWorkspaceNavigation';
import {adminBootstrapKey} from '../types/admin';
import type {DashboardRosterPlan} from '../types/roster';
const route=useRoute(),router=useRouter(),bootstrap=inject(adminBootstrapKey)!;
const plan=ref<DashboardRosterPlan|null>(null),loaded=ref<JointLoad|null>(null),error=ref(''),busy=ref(false);
let controller:AbortController|undefined;
async function load(){
  controller?.abort();const current=new AbortController();controller=current;busy.value=true;error.value='';
  try{
    const value=route.params.id;if(typeof value!=='string'||!(/^[1-9]\d*$/.test(value))||!Number.isSafeInteger(Number(value)))throw new Error('Ongeldig rooster.');
    const result=await fetchDashboardRoster(Number(value),current.signal);
    if(current.signal.aborted)return;
    const linked=linkedWorkspaceLocation(result.plan);
    if(linked){await router.replace(linked);return;}
    const workspace=await jointWorkspace<JointLoad>([result.plan],bootstrap.rosterSingleWorkspaceCsrfToken!,'read',null,null,undefined,current.signal);
    if(!current.signal.aborted){plan.value=result.plan;loaded.value=workspace;}
  }catch(e){if(!current.signal.aborted)error.value=e instanceof Error?e.message:'Het rooster kon niet worden geladen.';}
  finally{if(!current.signal.aborted)busy.value=false;}
}
watch(()=>route.params.id,()=>{plan.value=null;loaded.value=null;void load();},{immediate:true});
onBeforeUnmount(()=>controller?.abort());
function back(){if(plan.value)void router.push({name:'roster-detail',params:{id:plan.value.id}});}
</script>
<template>
  <section class="single-workspace-page">
    <header><p class="admin-eyebrow">Roosterbeheer</p><h1>Handmatig aanpassen</h1></header>
    <p v-if="busy" role="status">Rooster laden…</p>
    <div v-if="error" class="admin-inline-error" role="alert"><p>{{error}}</p><AdminButton @click="load">Opnieuw laden</AdminButton><RouterLink :to="{name:'rosters'}">Terug naar roosters</RouterLink></div>
    <template v-if="plan && loaded">
      <RosterJointWorkspace v-if="loaded.readiness.canOpenJoint" :key="`${plan.id}:${plan.revision}`" :plans="[plan]" :loaded="loaded" @back="back" @refresh="load" />
      <section v-else class="admin-card"><h2>{{plan.school.name}}</h2><p>Rond de lesindeling eerst af in de wizard. Daarna kun je hier modules en docenten aanpassen.</p><RosterJointIssues :plans="[plan]" :issues="loaded.readiness.schools.flatMap(s=>s.issues)" /><AdminButton @click="back">Naar rooster en wizard</AdminButton></section>
    </template>
  </section>
</template>
<style scoped>.single-workspace-page{display:grid;gap:1.25rem;min-width:0}.single-workspace-page h1{margin:0}.single-workspace-page .admin-inline-error{display:grid;gap:.75rem}</style>
