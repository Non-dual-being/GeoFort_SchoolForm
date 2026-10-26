export const programVariantOptions = [
  { value: 'all', label: 'Alle programmavarianten' },
  { value: 'day-four', label: 'Dagprogramma · vier modules' },
  { value: 'day-five', label: 'Dagprogramma · vijf modules' },
  { value: 'morning', label: 'Ochtendprogramma' },
];
export function normalizeProgramVariant(value: unknown): string {
  return programVariantOptions.some(option => option.value === value) ? String(value) : 'all';
}
