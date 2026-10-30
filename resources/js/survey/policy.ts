export const ratings = [
  { value: "dissatisfied", label: "Ontevreden" },
  { value: "neutral", label: "Neutraal" },
  { value: "satisfied", label: "Tevreden" },
] as const;
export type Satisfaction = typeof ratings[number]["value"];
export function wordCount(text: string): number {
  return (text.match(/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*(?:[\u2019\u0027\u002D][\p{L}\p{N}][\p{L}\p{N}\p{M}]*)*/gu) ?? []).length;
}
