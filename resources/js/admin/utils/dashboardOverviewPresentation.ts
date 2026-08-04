const fullDate = new Intl.DateTimeFormat("nl-NL", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
const shortDate = new Intl.DateTimeFormat("nl-NL", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });

function date(value: string): Date { return new Date(`${value}T00:00:00Z`); }
export function formatDashboardDate(value: string, full = false): string {
  const formatted = (full ? fullDate : shortDate).format(date(value));
  return full ? formatted.charAt(0).toUpperCase() + formatted.slice(1) : formatted;
}
export function relativeDashboardDate(value: string, today: string): string {
  const days = Math.round((date(value).getTime() - date(today).getTime()) / 86_400_000);
  if (days === 0) return "vandaag";
  if (days === 1) return "morgen";
  return days > 1 ? `over ${days} dagen` : `${Math.abs(days)} dagen geleden`;
}
