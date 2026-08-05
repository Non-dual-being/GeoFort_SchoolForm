import type { RevenueScope } from "../types/bookingRevenue";

export function normalizeRevenueScope(value: unknown): RevenueScope {
  return typeof value === "string" && ["definitive", "option", "combined"].includes(value)
    ? value as RevenueScope
    : "definitive";
}

export function normalizeRevenuePage(value: unknown): number {
  const page = Number(typeof value === "string" ? value : value ?? 1);
  return Number.isInteger(page) && page > 0 ? page : 1;
}

export function relevantRevenuePages(currentPage: number, totalPages: number): number[] {
  return Array.from({ length: totalPages }, (_, index) => index + 1)
    .filter((page) => page === 1 || page === totalPages || Math.abs(page - currentPage) <= 1);
}
