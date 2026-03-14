<script setup lang="ts">
import { ref, onMounted } from "vue";
import AppFooter from "../components/layout/AppFooter.vue";
import "./../../css/error/index.css";


const props = defineProps<{
  message: string;
}>();

const emit = defineEmits<{
  retry: [];
}>();

const visible = ref(false);
onMounted(() => {
  requestAnimationFrame(() => {
    visible.value = true;
  });
});

const goHome = () => {
  window.location.href = "https://www.geofort.nl"
}
</script>

<template>
  <div class="app-shell">
    <main
      class="error-page"
      :class="{ 'error-page--visible': visible }"
    >
      <div class="error-hero">
        <div
          class="error-hero__icon"
          aria-hidden="true"
        >
          !
        </div>

        <h1 class="error-hero__title">Er is iets misgegaan</h1>

        <p class="error-hero__sub">
          Uw boeking kon niet worden verwerkt. Onze excuses voor het
          ongemak.
        </p>

        <div
          class="error-hero__detail"
          role="alert"
        >
          <span class="error-hero__detail-label">Foutmelding:</span>
          {{ message }}
        </div>
      </div>

      <div class="error-actions">
        <button
          class="error-actions__btn error-actions__btn--primary"
          type="button"
          @click="emit('retry')"
        >
          ↩ Opnieuw proberen
        </button>

        <button
          class="error-actions__btn"
          type="button"
          @click="goHome"
        >
          Terug naar GeoFort.nl
        </button>
      </div>

      <p class="error-help">
        Lukt het niet? Neem contact op via
        <a
          href="mailto:info@geofort.nl"
          class="error-help__link"
        >
          onderwijs@geofort.nl
        </a>
      </p>
    </main>

    <AppFooter />
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  width: 100%;
}

/* ── Page ──────────────────────────────────────────────── */

.error-page * {
  font: var(--font-primary)
}

.error-page {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 1rem;
  background-color: var(--color-bg-body-gray---cool-blue);
  text-align: center;
  opacity: 0;
  transform: translateY(12px);
  
}

.error-page--visible {
  animation: fadein 600ms ease forwards;
}

@keyframes fadein {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ── Hero ──────────────────────────────────────────────── */
.error-hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  max-width: 520px;
  margin-bottom: 2.5rem;
}

.error-hero__icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background-color: var(--color-main-red);
  color: white;
  font-size: 2.8rem;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.5rem;
  animation: pop 500ms cubic-bezier(0.175, 0.885, 0.32, 1.275) 300ms both;
}

@keyframes pop {
  from {
    transform: scale(0);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}

.error-hero__title {
  font-size: var(--fs-big);
  color: var(--color-main-blue-dark);
  font-family: var(--font-headings);
  font-weight: 700;
  margin-bottom: 1rem;
}

.error-hero__sub {
  color: #555;
  font-size: 1rem;
  line-height: 1.6;
  margin-bottom: 1.5rem;
}

.error-hero__detail {
  background-color: #fff3f3;
  border: 1px solid #f5c0c0;
  border-left: 4px solid var(--color-main-red);
  border-radius: 0.5rem;
  padding: 1rem 1.25rem;
  font-size: 1rem;
  color: #7a1a1a;
  text-align: left;
  width: 100%;
}

.error-hero__detail-label {
  font-weight: 700;
  display: block;
  margin-bottom: 0.25rem;
}

/* ── Acties ────────────────────────────────────────────── */
.error-actions {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  justify-content: center;
  margin-bottom: 2rem;
}

.error-actions__btn {
  padding: 0.8rem 1.75rem;
  border-radius: 0.5rem;
  border: 2px solid var(--color-main-blue-dark);
  background-color: transparent;
  color: var(--color-main-blue-dark);
  font-weight: 600;
  font-size: 0.95rem;
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.error-actions__btn:hover {
  background-color: var(--color-main-blue-dark);
  color: white;
}

.error-actions__btn--primary {
  background-color: var(--color-main-blue-dark);
  color: white;
}

.error-actions__btn--primary:hover {
  background-color: white;
  color: var(--color-main-blue-dark);
}

/* ── Hulp ──────────────────────────────────────────────── */
.error-help {
  font-size: 0.9rem;
  color: #666;
}

.error-help__link {
  color: var(--color-main-blue-dark);
  font-weight: 600;
  text-decoration: underline;
}

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 480px) {
  .error-actions {
    flex-direction: column;
    width: 100%;
    max-width: 320px;
  }

  .error-actions__btn {
    width: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .error-page,
  .error-hero__icon {
    animation: none;
    opacity: 1;
    transform: none;
  }
}
</style>