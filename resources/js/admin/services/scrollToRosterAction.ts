import { nextTick } from "vue";

/** Call only after an explicit action opens a section or returns a result. */
export async function scrollToRosterAction(target: () => HTMLElement | null | undefined): Promise<void> {
  await nextTick();
  const element = target();
  if (!element || !element.isConnected) return;
  const top = element.getBoundingClientRect().top;
  if (top >= 16 && top <= window.innerHeight - 100) return;
  element.style.scrollMarginTop = "1rem";
  element.scrollIntoView({
    behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
    block: "start",
  });
}
