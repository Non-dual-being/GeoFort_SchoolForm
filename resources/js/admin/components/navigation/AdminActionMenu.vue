<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import { MoreVertical } from "lucide-vue-next";

interface Props {
  label?: string;
  align?: "start" | "end";
}

withDefaults(defineProps<Props>(), {
  label: "Acties openen",
  align: "end",
});

const open = ref(false);
const rootElement = ref<HTMLElement | null>(null);

function toggleMenu(): void {
  open.value = !open.value;
}

function closeMenu(): void {
  open.value = false;
}

function handleDocumentClick(event: MouseEvent): void {
  const root = rootElement.value;

  if (!root || !(event.target instanceof Node)) {
    return;
  }

  if (!root.contains(event.target)) {
    closeMenu();
  }
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") {
    closeMenu();
  }
}

onMounted(() => {
  document.addEventListener("click", handleDocumentClick);
  document.addEventListener("keydown", handleKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener("click", handleDocumentClick);
  document.removeEventListener("keydown", handleKeydown);
});
</script>

<template>
  <div
    ref="rootElement"
    class="admin-action-menu"
  >
    <button
      type="button"
      class="admin-action-menu__trigger"
      :aria-label="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click.stop="toggleMenu"
    >
      <MoreVertical
        :size="20"
        aria-hidden="true"
      />
    </button>

    <div
      v-if="open"
      class="admin-action-menu__panel"
      :class="`admin-action-menu__panel--${align}`"
      role="menu"
      @click="closeMenu"
    >
      <slot />
    </div>
  </div>
</template>