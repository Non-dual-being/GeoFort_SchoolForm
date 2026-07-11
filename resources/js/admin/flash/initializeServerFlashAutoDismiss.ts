import { ADMIN_FLASH_DISMISS_ANIMATION_MS } from "./flashTiming";

export function initializeServerFlashAutoDismiss(): void {
  document.querySelectorAll<HTMLElement>('[data-flash-type="success"][data-auto-dismiss]')
    .forEach((flash) => {
      const duration = Number(flash.dataset.autoDismiss);
      if (!Number.isFinite(duration) || duration <= 0) return;

      window.setTimeout(() => {
        flash.classList.add("is-dismissing");
        window.setTimeout(() => flash.remove(), ADMIN_FLASH_DISMISS_ANIMATION_MS);
      }, duration);
    });
}
