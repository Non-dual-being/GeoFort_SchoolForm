<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";

defineProps<{ busy: boolean }>();
const emit = defineEmits<{ cancel: []; confirm: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

onMounted(() => {
  dialog.value?.showModal();
  dialog.value?.querySelector<HTMLButtonElement>("[data-reset-cancel]")?.focus();
});
onBeforeUnmount(() => dialog.value?.close());
</script>

<template>
  <dialog
    ref="dialog"
    class="admin-roster-reset-dialog admin-card"
    aria-labelledby="roster-reset-title"
    aria-describedby="roster-reset-description"
    @cancel.prevent="!busy && emit('cancel')"
  >
    <h2 id="roster-reset-title">Lesindeling leegmaken?</h2>
    <p id="roster-reset-description">
      Alle ingeplande lessen en bijbehorende groeps- en personeelstoewijzingen
      worden verwijderd. De groepen, dagindeling, geselecteerde medewerkers en
      algemene roosterinstellingen blijven bestaan. Deze actie kan niet
      ongedaan worden gemaakt.
    </p>
    <footer>
      <AdminButton
        variant="secondary"
        data-reset-cancel
        autofocus
        :disabled="busy"
        @click="emit('cancel')"
      >Annuleren</AdminButton>
      <AdminButton :loading="busy" @click="emit('confirm')">
        Lesindeling verwijderen
      </AdminButton>
    </footer>
  </dialog>
</template>

