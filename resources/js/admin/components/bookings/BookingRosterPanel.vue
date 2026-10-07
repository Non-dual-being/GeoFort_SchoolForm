<script setup lang="ts">
import { inject, ref } from "vue";
import { useRouter } from "vue-router";

import AdminButton from "../form/AdminButton.vue";
import { createDashboardRoster } from "../../services/dashboardRosterApi";
import { adminBootstrapKey } from "../../types/admin";

const props = defineProps<{
  bookingId: number;
}>();

const router = useRouter();
const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const rosterPlanCsrfToken = bootstrap.rosterPlanCsrfToken;

const loading = ref(false);
const error = ref<string | null>(null);

async function openRoster(): Promise<void> {
  if (loading.value) return;

  loading.value = true;
  error.value = null;

  try {
    const result = await createDashboardRoster(
      props.bookingId,
      rosterPlanCsrfToken,
    );

    await router.push({
      name: "roster-detail",
      params: { id: result.plan.id },
    });
  } catch {
    error.value =
      "Het rooster kon niet worden aangemaakt of geopend. Er zijn geen aanvraaggegevens gewijzigd.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <section class="admin-card">
    <h2>Rooster</h2>
    <p>
      Start het operationele dagrooster vanuit deze aanvraag.
      Schoolgegevens, bezoekdatum en het aantal roostergroepen worden overgenomen.
    </p>

    <p v-if="error" class="admin-inline-error" role="alert">
      {{ error }}
    </p>

    <AdminButton :loading="loading" @click="openRoster">
      Rooster maken / openen
    </AdminButton>
  </section>
</template>