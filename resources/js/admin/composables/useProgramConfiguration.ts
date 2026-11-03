import { onBeforeUnmount, ref } from "vue";
import { requestProgramConfiguration } from "../services/programConfigurationApi";
import type { ProgramConfiguration } from "../utils/programConfiguration";

export function useProgramConfiguration() {
  const config = ref<ProgramConfiguration | null>(null);
  const busy = ref(false), error = ref(""), message = ref("");
  const controller = new AbortController();
  let csrf = "", timer: ReturnType<typeof setTimeout> | undefined;
  async function request(next?: ProgramConfiguration): Promise<boolean> {
    if (busy.value) return false;
    busy.value = true; error.value = ""; message.value = ""; clearTimeout(timer);
    try {
      const result = await requestProgramConfiguration(controller.signal, next ? { config: next, csrf } : undefined);
      config.value = result.data;
      if (result.csrfToken) csrf = result.csrfToken;
      if (next) {
        message.value = "Instellingen opgeslagen. Het formulier en dashboard gebruiken deze vanaf nu.";
        timer = setTimeout(() => { message.value = ""; }, 6000);
      }
      return true;
    } catch (caught) {
      if (!controller.signal.aborted) error.value = caught instanceof Error ? caught.message : "Instellingen laden mislukt.";
      return false;
    } finally { busy.value = false; }
  }
  onBeforeUnmount(() => { controller.abort(); clearTimeout(timer); });
  return { config, busy, error, message, load: () => request(), save: (next: ProgramConfiguration) => request(next) };
}
