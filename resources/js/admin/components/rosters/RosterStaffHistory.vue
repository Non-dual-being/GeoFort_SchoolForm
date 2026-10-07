<script setup lang="ts">
import type { RosterStaffHistoryItem, RosterStaffValues } from "../../types/rosterStaff";
defineProps<{ items: RosterStaffHistoryItem[] }>();
const fields: Array<{ key: keyof RosterStaffValues; label: string }> = [
  { key: "name", label: "Weergavenaam" }, { key: "isActive", label: "Actief" },
  { key: "employmentType", label: "Dienstverband" }, { key: "canGuide", label: "Kan begeleiden" },
  { key: "canCook", label: "Kan koken" }, { key: "preferences", label: "Modulevaardigheden / rangen" },
];
function text(value: unknown): string {
  if (value === undefined || value === null) return "—";
  if (typeof value === "boolean") return value ? "Ja" : "Nee";
  if (value === "paid") return "Betaald";
  if (value === "volunteer") return "Vrijwilliger";
  if (Array.isArray(value)) return value.map(p => p.moduleKey + ": " + p.rank).join(", ") || "Geen";
  return String(value);
}
function changes(item: RosterStaffHistoryItem) {
  return fields.filter(field => JSON.stringify(item.before?.[field.key]) !== JSON.stringify(item.after[field.key]));
}
</script>
<template>
  <details class="admin-roster-staff-history">
    <summary>Wijzigingshistorie (laatste {{ items.length }})</summary>
    <p v-if="!items.length">Nog geen beheerwijzigingen vastgelegd.</p>
    <ol>
      <li v-for="item in items" :key="item.id">
        <strong>Versie {{ item.version }} · {{ item.createdAt }} UTC · beheerder #{{ item.actorId }}</strong>
        <p v-for="field in changes(item)" :key="field.key">
          {{ field.label }}: {{ text(item.before?.[field.key]) }} → {{ text(item.after[field.key]) }}
        </p>
        <p v-if="item.impact.length">Bevestigde impact: {{ item.impact.map(p => 'rooster #' + p.planId).join(", ") }}</p>
      </li>
    </ol>
  </details>
</template>
