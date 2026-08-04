const euroFormatter = new Intl.NumberFormat("nl-NL", { style: "currency", currency: "EUR" });

export function formatEuroCents(cents: number): string {
  return euroFormatter.format(cents / 100);
}
