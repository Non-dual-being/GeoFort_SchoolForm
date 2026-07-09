<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";

const now = ref(new Date());
let timer: number | undefined;

onMounted(() => {
  timer = window.setInterval(() => {
    now.value = new Date();
  }, 60 * 60 * 1000);
});

onUnmounted(() => {
  if (timer) {
    window.clearInterval(timer);
  }
});

const year = computed(() => now.value.getFullYear());

const geofortLogoSrc = "/assets/images/geofort_logo.png";
</script>

<template>
  <footer class="footer">
    <div class="footer__inner">
      <p class="footer__copy">
        &copy;<span>{{ year }}</span> GeoFort
      </p>

      <img
        class="footer__logo"
        :src="geofortLogoSrc"
        alt="GeoFort"
      />
    </div>
  </footer>
</template>

<style scoped>
.footer {
  background-color: var(--color-main-blue-dark);
  color: white;
  position: relative;
  overflow-y: visible;
  width: 100%;
  max-width: 100%;
  height: auto;
}

.footer__inner {
  display: grid;
  overflow-x: hidden;
  max-width: 100%;
  height: auto;
  min-height: 4rem;
  margin: 0 auto;
  padding: 1rem 1.5rem;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
}

.footer__copy {
  grid-column: 2;
  justify-self: center;
  margin: 0;
}

.footer__logo {
  position: absolute;
  right: 120px;
  top: 0;
  transform: translateY(-55%);
  width: 85px;
  height: auto;
  z-index: 10;
}

@media (max-width: 768px) {
  .footer__inner {
    grid-template-columns: 1fr;
  }

  .footer__copy {
    display: none;
  }

  .footer__logo {
    position: static;
    transform: none;
    justify-self: center;
    grid-column: 1 / -1;
    margin-top: 1rem;
  }
}
</style>