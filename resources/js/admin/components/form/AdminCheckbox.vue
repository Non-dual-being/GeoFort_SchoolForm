<script setup lang="ts">
defineOptions({ inheritAttrs: false });
withDefaults(defineProps<{ modelValue: boolean; disabled?: boolean; label: string; name?: string }>(), { disabled: false });
const emit = defineEmits<{ "update:modelValue": [value: boolean] }>();
</script>

<template>
  <span class="admin-checkbox">
    <input v-bind="$attrs" class="admin-checkbox__input" type="checkbox" :name="name" :checked="modelValue" :disabled="disabled" :aria-label="label"
      @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
    <span class="admin-checkbox__box" aria-hidden="true">
      <svg viewBox="0 0 16 16" fill="none"><path d="m3 8 3.2 3.2L13 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </span>
  </span>
</template>

<style scoped>
.admin-checkbox { position: relative; display: inline-grid; width: 1.35rem; height: 1.35rem; flex: 0 0 auto; vertical-align: middle; }
.admin-checkbox__input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; z-index: 1; cursor: pointer; }
.admin-checkbox__box { display: grid; place-items: center; border: 1.5px solid #8b9fbc; border-radius: .35rem; background: white; color: white; pointer-events: none; }
.admin-checkbox__box svg { width: 1rem; height: 1rem; visibility: hidden; }
.admin-checkbox__input:checked + .admin-checkbox__box { background: var(--admin-primary, #102454); border-color: var(--admin-primary, #102454); }
.admin-checkbox__input:checked + .admin-checkbox__box svg { visibility: visible; }
.admin-checkbox__input:focus-visible + .admin-checkbox__box { outline: 3px solid #80a8dc; outline-offset: 3px; }
.admin-checkbox__input:hover:not(:disabled) + .admin-checkbox__box { border-color: #244b80; }
.admin-checkbox__input:disabled { cursor: not-allowed; }
.admin-checkbox__input:disabled + .admin-checkbox__box { opacity: .45; }
@media (forced-colors: active) { .admin-checkbox__input { appearance: auto; opacity: 1; } .admin-checkbox__box { visibility: hidden; } }
</style>
