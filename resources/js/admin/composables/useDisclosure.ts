import { readonly, ref, useId } from "vue";

export function useDisclosure(options: { defaultOpen?: boolean } = {}) {
  const isOpen = ref(options.defaultOpen ?? false);
  const contentId = `admin-disclosure-${useId()}`;

  const open = (): void => { isOpen.value = true; };
  const close = (): void => { isOpen.value = false; };
  const toggle = (): void => { isOpen.value = !isOpen.value; };

  return {
    isOpen: readonly(isOpen),
    contentId,
    toggle,
    open,
    close,
  };
}
