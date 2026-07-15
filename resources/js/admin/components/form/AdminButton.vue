<script setup lang="ts">
type AdminButtonVariant =
  | "primary"
  | "secondary"
  | "danger"
  | "ghost";

interface Props {
  type?: "button" | "submit" | "reset";
  variant?: AdminButtonVariant;
  disabled?: boolean;
  loading?: boolean;
}

withDefaults(defineProps<Props>(), {
  type: "button",
  variant: "primary",
  disabled: false,
  loading: false,
});
</script>

<template>
  <button
    class="admin-button"
    :class="`admin-button--${variant}`"
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span
      v-if="loading"
      class="admin-button__spinner"
      aria-hidden="true"
    />

    <span class="admin-button__content">
      <slot />
    </span>
  </button>
</template>