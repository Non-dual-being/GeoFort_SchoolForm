<script setup lang="ts">
import { RouterLink } from "vue-router";

defineProps<{ publicBookingUrl: string }>();
</script>

<template>
  <footer class="admin-footer">
    <div class="admin-footer__inner">
      <div class="admin-footer__brand">
        <span class="admin-footer__logo-surface">
          <img :src="'/assets/images/geofort_logo.png'" alt="GeoFort">
        </span>
        <span>
          <strong>GeoFort Onderwijs</strong>
          <small>Onderwijsformulier 2.0 · Beheerdashboard</small>
        </span>
      </div>
      <nav class="admin-footer__links" aria-label="Footernavigatie">
        <RouterLink to="/">Dashboardhome</RouterLink>
        <a :href="publicBookingUrl" target="_blank" rel="noopener noreferrer">
          Publieke reserveringspagina
          <span class="sr-only">(opent in een nieuw tabblad)</span>
        </a>
      </nav>
    </div>
  </footer>
</template>
