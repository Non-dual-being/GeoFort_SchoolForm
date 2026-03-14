<script setup lang="ts">
import { ref } from "vue";
import BookingFormView from "./../views/BookingGeoFormView.vue";
import BookingSuccessView from "./../views/BookingSuccessView.vue"; 
import BookingErrorView from "./../views/BookingErrorView.vue";

// Als de bestanden nog niet bestaan, maak dummy componenten of fix de imports.

type AppView = "form" | "success" | "error";

const currentView = ref<AppView>("form");
const serverErrorMessage = ref("");

function onSuccess() {
  currentView.value = "success";
}

// Hier zorgen we dat het argument matcht met wat de FormView emit ({message: string})
function onServerError(payload: { message: string }) {
  serverErrorMessage.value = payload.message 
  currentView.value = "error";
}

function onRetry() {
  currentView.value = "form";
}
</script>

<template>
  <BookingFormView
    v-if="currentView === 'form'"
    @success="onSuccess"
    @server-error="onServerError"
  />

  <BookingSuccessView
    v-else-if="currentView === 'success'"
    @new-booking="onRetry"
  />

  <BookingErrorView
    v-else-if="currentView === 'error'"
    :message="serverErrorMessage"
    @retry="onRetry"
  />

</template>