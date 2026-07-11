import { onBeforeUnmount, ref } from "vue";
import type { AdminFlashMessage } from "../types/admin";
import { ADMIN_SUCCESS_FLASH_DURATION_MS } from "../flash/flashTiming";

export interface DisplayedAdminFlash extends AdminFlashMessage {
  id: number;
  dismissing: boolean;
}

export function useAdminFlash(initialMessages: AdminFlashMessage[]) {
  let nextId = 1;
  const timers = new Map<number, number>();
  const messages = ref<DisplayedAdminFlash[]>(
    initialMessages.map((message) => ({ ...message, id: nextId++, dismissing: false })),
  );

  function dismiss(id: number): void {
    const message = messages.value.find((item) => item.id === id);
    if (message) message.dismissing = true;
  }

  function remove(id: number): void {
    messages.value = messages.value.filter((message) => message.id !== id);
    const timer = timers.get(id);
    if (timer !== undefined) window.clearTimeout(timer);
    timers.delete(id);
  }

  for (const message of messages.value) {
    if (message.type === "success" && message.autoDismiss) {
      timers.set(message.id, window.setTimeout(() => dismiss(message.id), ADMIN_SUCCESS_FLASH_DURATION_MS));
    }
  }

  onBeforeUnmount(() => timers.forEach((timer) => window.clearTimeout(timer)));
  return { messages, dismiss, remove };
}
