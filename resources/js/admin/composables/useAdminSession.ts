import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { AdminBootstrapData } from '../types/admin';
import { createSessionMonitor } from '../services/adminSessionMonitor';

export function useAdminSession(bootstrap: AdminBootstrapData) {
  const expired = ref(false);
  const warning = ref(false);
  let timer: ReturnType<typeof setInterval> | undefined;
  let requestController: AbortController | undefined;
  const monitor = createSessionMonitor({
    timeoutSeconds: bootstrap.sessionTimeoutSeconds,
    now: () => Date.now(),
    warning: value => { warning.value = value; },
    request: async (active, token) => {
      requestController = new AbortController();
      const timeout = window.setTimeout(() => requestController?.abort(), 5000);
      try {
        const response = await fetch('/api/admin/session.php', {
          method: active ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: { Accept: 'application/json', ...(active ? { 'X-CSRF-Token': token } : {}) },
          signal: requestController.signal,
        });
        if (!response.ok) return { status: response.status };
        const data = await response.json();
        return { status: response.status, remainingSeconds: data.remainingSeconds, csrfToken: data.csrfToken };
      } finally { window.clearTimeout(timeout); }
    },
    expired: serverEnded => { void leaveDashboard(serverEnded); },
  });

  async function leaveDashboard(serverEnded: boolean) {
    expired.value = true;
    // Unmount editors first: remove unsaved-change guards and sensitive dashboard content.
    await nextTick();
    if (serverEnded) {
      window.location.replace('/auth/login-page.php');
      return;
    }
    // Offline/failed status check: still perform the normal CSRF-protected logout POST.
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '/auth/logout.php';
    for (const [name, value] of Object.entries({ _csrf: bootstrap.logoutCsrfToken, reason: 'inactivity' })) {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value;
      form.append(input);
    }
    document.body.append(form);
    form.submit();
  }

  const onActivity = (event: Event) => {
    if (event.isTrusted && document.visibilityState === 'visible') void monitor.activity();
  };
  // Focus/visibility is not itself activity: check first, especially after sleep or another tab's logout.
  const onResume = () => { if (document.visibilityState === 'visible') void monitor.tick(true); };
  const events = ['pointerdown', 'keydown', 'input', 'wheel'] as const;
  onMounted(() => {
    for (const name of events) window.addEventListener(name, onActivity, { passive: true, capture: true });
    window.addEventListener('focus', onResume);
    window.addEventListener('pageshow', onResume);
    document.addEventListener('visibilitychange', onResume);
    timer = window.setInterval(() => { void monitor.tick(); }, 1000);
    void monitor.tick(true);
  });
  onBeforeUnmount(() => {
    monitor.stop(); requestController?.abort(); window.clearInterval(timer);
    for (const name of events) window.removeEventListener(name, onActivity, true);
    window.removeEventListener('focus', onResume);
    window.removeEventListener('pageshow', onResume);
    document.removeEventListener('visibilitychange', onResume);
  });
  return { expired, warning, staySignedIn: () => monitor.activity(true) };
}
