<script setup lang="ts">
import { CircleHelp } from "lucide-vue-next";
import { useDisclosure } from "../../composables/useDisclosure";

const props = withDefaults(defineProps<{
  label?: string;
  defaultOpen?: boolean;
}>(), {
  label: "Toon uitleg",
  defaultOpen: false,
});

const { isOpen, contentId, toggle } = useDisclosure({ defaultOpen: props.defaultOpen });
</script>

<template>
  <div class="admin-collapsible-help">
    <button
      type="button"
      class="admin-collapsible-help__trigger"
      :aria-expanded="isOpen"
      :aria-controls="contentId"
      :aria-label="label"
      @click="toggle"
    >
      <CircleHelp :size="18" aria-hidden="true" />
      <span class="admin-visually-hidden">{{ label }}</span>
    </button>
    <div
      :id="contentId"
      class="admin-collapsible-help__region"
      :class="{ 'is-open': isOpen }"
      :aria-hidden="!isOpen"
      :inert="!isOpen"
    >
      <div class="admin-collapsible-help__content">
        <slot />
      </div>
    </div>
  </div>
</template>
