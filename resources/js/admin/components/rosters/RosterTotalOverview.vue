<script setup lang="ts">
import RosterDaySchedule from "./RosterDaySchedule.vue";
import RosterTeacherSchedule from "./RosterTeacherSchedule.vue";
import type { DashboardRosterPlan } from "../../types/roster";

defineProps<{ plan: DashboardRosterPlan }>();
</script>

<template>
  <section class="admin-roster-total-overview">
    <div class="admin-card admin-roster-total-overview__meta">
      <div><span>School</span><strong>{{ plan.school.name }}</strong></div>
      <div><span>Datum</span><strong>{{ plan.visitDate }}</strong></div>
      <div><span>Leerlingen</span><strong>{{ plan.education.studentCount ?? "Onbekend" }}</strong></div>
      <div><span>Programma</span><strong>{{ plan.education.programLabel }}</strong></div>
      <div><span>Keuzemodule</span><strong>{{ plan.education.choiceModuleLabel ?? "Niet van toepassing" }}</strong></div>
      <div><span>Schoolverantwoordelijke</span><strong>{{ plan.school.contactName || "Niet ingevuld" }}</strong></div>
      <div><span>Contact</span><strong>{{ plan.school.contactPhone || "Niet ingevuld" }}</strong></div>
      <div><span>Schoolbegeleiders</span><strong>{{ plan.school.supervisorCount ?? "Onbekend" }}</strong></div>
    </div>

    <RosterDaySchedule
      :groups="plan.groups"
      :sessions="plan.planning.sessions"
    />

    <RosterTeacherSchedule
      :sessions="plan.planning.sessions"
      :staff-catalog="plan.planning.staffCatalog"
      :selected-staff-ids="plan.planning.selectedStaffIds"
      :staffing-settings="plan.planning.staffingSettings"
    />
  </section>
</template>
