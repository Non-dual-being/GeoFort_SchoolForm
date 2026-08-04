export interface RevenueAmounts { visitInclVatCents: number; cateringInclVatCents: number; totalInclVatCents: number; totalExclVatCents: number; vatCents: number }
export interface RevenueCounts { bookingsTotal: number; definitive: number; option: number; rejected: number; missingPrice: number; studentsTotal: number; studentsPrimary: number; studentsSecondary: number }
export type PriceSnapshotState = "complete" | "historical_price_unavailable" | "invalid_input" | "missing";
export interface RevenueBooking { id: number; visitDate: string; schoolName: string; sectorKey: string; sectorLabel: string; studentCount: number; status: string; snapshotState: PriceSnapshotState; snapshotSequence: number | null; amounts: RevenueAmounts | null }
export interface BookingRevenueReport { period: { startDate: string; endDate: string }; counts: RevenueCounts; definitiveRevenue: RevenueAmounts; potentialRevenue: RevenueAmounts; bookings: RevenueBooking[] }
