<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import { X } from "lucide-vue-next";

interface Props {
  open: boolean;
  title: string;
  description?: string | null;
  closeLabel?: string;
  closeOnBackdrop?: boolean;
  closeOnEscape?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  description: null,
  closeLabel: "Dialoog sluiten",
  closeOnBackdrop: true,
  closeOnEscape: true,
});

const emit = defineEmits<{
  close: [];
}>();

const dialogElement = ref<HTMLDialogElement | null>(null);
let previouslyFocusedElement: HTMLElement | null = null;

async function openDialog(): Promise<void> {
  await nextTick();

  const dialog = dialogElement.value;

  if (!dialog || dialog.open) {
    return;
  }

  previouslyFocusedElement =
    document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;

  dialog.showModal();
}

function closeDialog(): void {
  const dialog = dialogElement.value;

  if (dialog?.open) {
    dialog.close();
  }

  emit("close");

  nextTick(() => {
    previouslyFocusedElement?.focus();
    previouslyFocusedElement = null;
  });
}

function handleCancel(event: Event): void {
  if (!props.closeOnEscape) {
    event.preventDefault();
    return;
  }

  event.preventDefault();
  closeDialog();
}

function handleBackdropClick(event: MouseEvent): void {
  if (!props.closeOnBackdrop) {
    return;
  }

  if (event.target === dialogElement.value) {
    closeDialog();
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      void openDialog();
      return;
    }

    const dialog = dialogElement.value;

    if (dialog?.open) {
      dialog.close();
    }
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  const dialog = dialogElement.value;

  if (dialog?.open) {
    dialog.close();
  }
});
</script>

<template>
  <dialog
    ref="dialogElement"
    class="admin-dialog"
    :aria-labelledby="`${title}-dialog-title`"
    @cancel="handleCancel"
    @click="handleBackdropClick"
  >
    <section class="admin-dialog__panel">
      <header class="admin-dialog__header">
        <div class="admin-dialog__heading">
          <h2
            :id="`${title}-dialog-title`"
            class="admin-dialog__title"
          >
            {{ title }}
          </h2>

          <p
            v-if="description"
            class="admin-dialog__description"
          >
            {{ description }}
          </p>
        </div>

        <button
          type="button"
          class="admin-dialog__close"
          :aria-label="closeLabel"
          @click="closeDialog"
        >
          <X
            :size="20"
            aria-hidden="true"
          />
        </button>
      </header>

      <div class="admin-dialog__body">
        <slot />
      </div>

      <footer
        v-if="$slots.footer"
        class="admin-dialog__footer"
      >
        <slot name="footer" />
      </footer>
    </section>
  </dialog>
</template>