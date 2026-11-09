export interface SessionReply {
  status: number;
  remainingSeconds?: number;
  csrfToken?: string;
}

export interface SessionMonitorOptions {
  timeoutSeconds: number;
  now: () => number;
  request: (active: boolean, token: string) => Promise<SessionReply>;
  expired: (serverEnded: boolean) => void;
  warning: (show: boolean) => void;
}

/** Polling only reads the shared server deadline; only actual interaction renews it. */
export function createSessionMonitor(options: SessionMonitorOptions) {
  let deadline = options.now() + options.timeoutSeconds * 1000;
  let token = '';
  let pending = false;
  let stopped = false;
  let dirty = false;
  let lastCheck = -Infinity;
  let lastRenewal = options.now();
  // Renew at most once per 20 seconds while active, also supporting shorter test timeouts.
  const renewEvery = Math.min(20_000, options.timeoutSeconds * 250);

  function expire(serverEnded: boolean) {
    if (stopped) return;
    stopped = true;
    options.warning(false);
    options.expired(serverEnded);
  }

  async function check(force = false) {
    if (stopped || pending) return;
    const now = options.now();
    const active = dirty && !!token && now < deadline && (force || now - lastRenewal >= renewEvery);
    if (!force && !active && now < deadline && now - lastCheck < Math.min(30_000, renewEvery)) return;
    pending = true;
    lastCheck = now;
    // Consume only the interactions included in this request; preserve new ones in flight.
    if (active) dirty = false;
    try {
      const result = await options.request(active, token);
      if (stopped) return;
      if (result.status === 401) { expire(true); return; }
      if (result.status !== 200 || !Number.isFinite(result.remainingSeconds) || !result.csrfToken) {
        throw new Error('Session status unavailable');
      }
      token = result.csrfToken;
      // Count network time conservatively, never granting time beyond the server deadline.
      deadline = now + result.remainingSeconds! * 1000;
      if (active) lastRenewal = now;
      if (deadline <= options.now()) expire(false);
    } catch {
      // Never extend on a failed request, and never renew an expired session after reconnecting.
      if (active) dirty = true;
      if (options.now() >= deadline) expire(false);
    } finally {
      pending = false;
    }
  }

  return {
    async tick(force = false) {
      if (stopped) return;
      options.warning(deadline - options.now() <= 60_000);
      await check(force);
      if (!stopped) options.warning(deadline - options.now() <= 60_000);
    },
    async activity(force = false) {
      if (stopped) return;
      // Resuming a sleeping laptop first verifies the deadline instead of reviving the session.
      if (options.now() >= deadline) { await check(true); return; }
      dirty = true;
      await check(force);
    },
    stop() { stopped = true; },
  };
}
