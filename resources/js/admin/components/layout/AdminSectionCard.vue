<script setup lang="ts">
interface Props {
  title: string;
  description?: string | null;
}

withDefaults(defineProps<Props>(), {
  description: null,
});
</script>

<template>
  <section class="admin-section-card">
    <header class="admin-section-card__header">
      <div>
        <h2 class="admin-section-card__title">
          {{ title }}
        </h2>

        <p
          v-if="description"
          class="admin-section-card__description"
        >
          {{ description }}
        </p>
      </div>

      <div
        v-if="$slots.actions"
        class="admin-section-card__actions"
      >
        <slot name="actions" />
      </div>
    </header>

    <div class="admin-section-card__body">
      <slot />
    </div>
  </section>
</template>