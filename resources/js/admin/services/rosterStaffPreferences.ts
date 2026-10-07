type Preference = { moduleKey: string; rank: number };

export function sameStaffPreferences(before: Preference[], after: Preference[]): boolean {
  const canonical = (items: Preference[]) => JSON.stringify(items.map(p => [p.moduleKey, p.rank]).sort((a, b) => String(a[0]).localeCompare(String(b[0]))));
  return canonical(before) === canonical(after);
}

export function nextStaffPreferenceRank(preferences: Preference[]): number | null {
  const rank = Math.max(0, ...preferences.map(p => Number(p.rank) || 0)) + 1;
  return Number.isInteger(rank) && rank <= 255 ? rank : null;
}

export function staffPreferenceErrors(preferences: Preference[]): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const preference of preferences) {
    if (!Number.isInteger(preference.rank) || preference.rank < 1 || preference.rank > 255) {
      errors[preference.moduleKey] = "Gebruik een geheel getal van 1 tot en met 255.";
    } else if (preferences.some(p => p.moduleKey !== preference.moduleKey && p.rank === preference.rank)) {
      errors[preference.moduleKey] = `Rang ${preference.rank} is al in gebruik. Kies een unieke rang.`;
    }
  }
  return errors;
}
