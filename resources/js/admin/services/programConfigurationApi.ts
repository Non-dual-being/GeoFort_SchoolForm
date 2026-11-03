import type { ProgramConfiguration } from "../utils/programConfiguration";

type ConfigurationResponse = { data: ProgramConfiguration; csrfToken?: string };
export async function requestProgramConfiguration(signal: AbortSignal, input?: { config: ProgramConfiguration; csrf: string }): Promise<ConfigurationResponse> {
  const response = await fetch("/api/admin/program-access.php", {
    signal, cache: "no-store", credentials: "same-origin",
    ...(input ? { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": input.csrf },
      body: JSON.stringify({ fiveModuleOpen: input.config.fiveModuleOpen, periods: input.config.periods, revision: input.config.revision }) } : {}),
  });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(response.status === 409
    ? "Een andere planner heeft de instellingen gewijzigd. Laad de actuele instellingen en controleer je wijziging opnieuw."
    : response.status === 401 ? "Je sessie is verlopen. Log opnieuw in."
    : response.status === 403 ? "Je hebt geen toegang tot deze wijziging. Log opnieuw in als planner of beheerder."
    : result.message ?? "De instellingen konden niet worden verwerkt. Probeer opnieuw.");
  return result;
}
