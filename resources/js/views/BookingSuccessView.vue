<script setup lang="ts">
import { ref, onMounted } from "vue";
import AppFooter from "./../components/layout/AppFooter.vue";

const emit = defineEmits<{
  "new-booking": [];
}>();
defineProps<{ mailDelivery: "sent" | "failed" }>();

const visible = ref(false);
onMounted(() => {
  requestAnimationFrame(() => {
    visible.value = true;
  });
});

const cards = [
  {
    id: "geofort",
    title: "GeoFort Onderwijs",
    description:
      "Lees meer over onze educatieve programma's en lesmodules op het GeoFort.",
    label: "Onderwijs op het GeoFort",
    icon: "🏰",
    highlight: false,
    action: () =>
      window.open("https://www.geofort.nl/onderwijs/", "_blank"),
  },
  {
    id: "booking",
    title: "Nieuwe Boeking",
    description:
      "Wilt u nog een bezoek plannen? Start direct een nieuwe boeking!",
    label: "Nieuwe Boeking",
    icon: "📋",
    highlight: true,
    action: () => emit("new-booking"),
  },
  {
    id: "gogeo",
    title: "GoGeo Lesmodules",
    description:
      "Bekijk onze interactieve GoGeo-lessen vol ontdekkingen en uitdagingen.",
    label: "Bekijk GoGeo",
    icon: "🌍",
    highlight: false,
    action: () =>
      window.open("https://www.gogeo.nl/lesmodules/", "_blank"),
  },
  {
    id: "geocraft",
    title: "GeoCraft Workshops",
    description:
      "Minecraft workshops op locatie via de GeoCraft website.",
    label: "GeoCraft website",
    icon: "⛏️",
    highlight: false,
    action: () =>
      window.open("https://workshops.geocraft.nl/", "_blank"),
  },
] as const;
</script>

<template>
  <div class="app-shell">
    <main
      class="success-page"
      :class="{ 'success-page--visible': visible }"
    >
      <div class="success-hero">
        <div
          class="success-hero__icon"
          aria-hidden="true"
        >
          ✓
        </div>
        <h1 class="success-hero__title">Bedankt voor uw boeking!</h1>
        <p v-if="mailDelivery === 'sent'" class="success-hero__sub">
          Uw aanvraag is succesvol ontvangen. U ontvangt spoedig een
          bevestiging via e-mail.
        </p>
        <p v-else class="success-hero__sub" role="status">
          Uw aanvraag is succesvol ontvangen en opgeslagen. Alleen de bevestigingsmail kon niet worden verzonden. U hoeft de aanvraag niet opnieuw te versturen.
        </p>
      </div>

      <section
        class="cards-section"
        aria-label="Vervolgopties"
      >
        <h2 class="cards-section__title">Wat wilt u nu doen?</h2>

        <div class="cards-grid">
          <article
            v-for="card in cards"
            :key="card.id"
            class="card"
            :class="{ 'card--highlight': card.highlight }"
          >
            <div
              class="card__icon"
              aria-hidden="true"
            >
              {{ card.icon }}
            </div>
            <h3 class="card__title">{{ card.title }}</h3>
            <p class="card__description">{{ card.description }}</p>
            <button
              class="card__btn"
              :class="{ 'card__btn--primary': card.highlight }"
              type="button"
              @click="card.action"
            >
              {{ card.label }}
            </button>
          </article>
        </div>
      </section>
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
.success-page {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 3rem 1rem 4rem;
  background-color: var(--color-bg-body-gray---cool-blue);
  opacity: 0;
  transform: translateY(12px);
}

.success-page--visible {
  animation: fadein 600ms ease forwards;
}

@keyframes fadein {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ── Hero ──────────────────────────────────────────────── */
.success-hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  margin-bottom: 3rem;
  max-width: 600px;
}

.success-hero__icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background-color: var(--color-main-blue-dark);
  color: white;
  font-size: 2.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.5rem;
  animation: pop 500ms cubic-bezier(0.175, 0.885, 0.32, 1.275) 400ms both;
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

.success-hero__title {
  font-size: var(--fs-big);
  color: var(--color-main-red);
  font-family: var(--font-headings);
  font-weight: 700;
  margin-bottom: 1rem;
}

.success-hero__sub {
  color: #555;
  font-size: 1.05rem;
  line-height: 1.6;
}

/* ── Cards ─────────────────────────────────────────────── */
.cards-section {
  width: 100%;
  max-width: 1100px;
}

.cards-section__title {
  text-align: center;
  color: var(--color-main-blue-dark);
  font-size: 1.3rem;
  margin-bottom: 2rem;
  font-weight: 600;
}

.cards-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
}

.card {
  background-color: white;
  border: 1px solid #dce3ec;
  border-radius: 1rem;
  padding: 1.75rem 1.25rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.75rem;
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(8, 21, 64, 0.12);
}

.card--highlight {
  border-color: var(--color-main-blue-dark);
  background-color: var(--color-main-blue-dark);
}

.card--highlight .card__title,
.card--highlight .card__description {
  color: white;
}

.card__icon {
  font-size: 2rem;
}

.card__title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-main-blue-dark);
}

.card__description {
  font-size: 0.9rem;
  color: #555;
  line-height: 1.5;
  flex: 1;
}

.card__btn {
  margin-top: auto;
  width: 100%;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  border: 2px solid var(--color-main-blue-dark);
  background-color: transparent;
  color: var(--color-main-blue-dark);
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.card__btn:hover {
  background-color: var(--color-main-blue-dark);
  color: white;
}

.card__btn--primary {
  background-color: var(--color-main-red);
  border-color: var(--color-main-red);
  color: white;
}

.card__btn--primary:hover {
  background-color: white;
  color: var(--color-main-red);
}

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 1024px) {
  .cards-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 576px) {
  .cards-grid {
    grid-template-columns: 1fr;
  }

  .success-hero__icon {
    width: 64px;
    height: 64px;
    font-size: 2rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .success-page,
  .success-hero__icon {
    animation: none;
    opacity: 1;
    transform: none;
  }
}
</style>
