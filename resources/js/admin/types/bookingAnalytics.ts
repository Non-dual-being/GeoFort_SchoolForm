export type SectorFilter = "all" | "primairOnderwijs" | "voortgezetOnderbouw" | "voortgezetBovenbouw";
export type PopulationFilter = "planning" | "confirmed" | "all";
export type ProgramFilter = "all" | "dag" | "ochtend";

export interface AnalyticsCriteria {
  startDate: string; endDate: string; effectiveStartDate: string | null; effectiveEndDate: string | null;
  normalized: boolean; hasOverlap: boolean; sector: SectorFilter; population: PopulationFilter; program: ProgramFilter;
}
export interface AnalyticsRow { [key: string]: string | number | null }
export interface DistributionRow {
  key: string; label: string; bookings: number; percentage: number; students: number;
  averageStudents?: number; denominator?: number;
  sectorDistribution?: Array<{ label: string; count: number }>;
}
export interface StudentProgramAnalysis {
  key: "dag" | "ochtend"; label: string; maximumCapacity: number; denominator: number;
  studentBins: DistributionRow[]; capacityBins: DistributionRow[];
}
export interface StudentCountAnalysis {
  bins: Array<{ key: string; label: string; min: number; max: number }>;
  capacityBins: Array<{ key: string; label: string }>;
  programs: StudentProgramAnalysis[]; invalidRecordCount: number;
  definitions: { studentBands: string; capacity: string }; context: string;
}
export interface CateringAnalysis {
  bookingProfiles: Array<{ key: string; label: string; count: number; percentage: number; students: number; denominator: number }>;
  schoolProfiles: Array<{ key: string; label: string; count: number; percentage: number }>;
  programBreakdown: Array<{ label: string; bookings: number; withCatering: number; percentage: number }>;
  sectorBreakdown: Array<{ label: string; bookings: number; withCatering: number; percentage: number }>;
  sizeBandBreakdown: Array<{ label: string; bookings: number; withCatering: number; percentage: number }>;
  denominators: { bookings: number; schools: number };
  insights: { bookingPercentage?: number; schoolAtLeastOncePercentage?: number; averageStudentsWithCatering?: number; averageStudentsWithoutCatering?: number };
  definitions: { catering?: string; schoolIdentity?: string }; context: string;
}
export type YearMetric = "plannedStudents" | "activeBookings" | "confirmedBookings" | "uniqueSchools" | "uniqueVisitDays" | "averageStudents" | "cateringPercentage" | "rejectionPercentage";
export interface YearAnalysisRow {
  year: number; coverageStart: string; coverageEnd: string; isFullCalendarYearWithinSelection: boolean;
  relationToCurrentYear: "past" | "current" | "future";
  comparisonStatus: "comparable" | "partialSelection" | "currentBookingStand" | "futureBookingStand";
  metrics: Record<YearMetric, number>;
  programMix: Array<{ label: string; count: number; percentage: number }>;
  sectorMix: Array<{ label: string; count: number; percentage: number }>;
}
export interface YearlyAnalysis {
  generatedAt: string; analyticsAsOfDate: string; years: YearAnalysisRow[];
  availableMetrics: Record<YearMetric, string>; comparisonAvailability: string; context: string;
}
export type MonthlySeasonMetric = "students" | "bookings" | "visitDays";
export type WeekdaySeasonMetric = "students" | "averageStudentsPerVisitDate" | "bookings" | "averageBookingsPerVisitDate" | "averageSchoolsPerVisitDate";
export type SeasonMetric = MonthlySeasonMetric | WeekdaySeasonMetric;
export type SeasonView = "monthly" | "weekday";
export interface MonthlyBucket { year: number; month: number; label: string; bookings: number; uniqueVisitDates: number; uniqueSchools: number; students: number; averageStudentsPerVisitDate: number }
export interface VisitDateBucket { date: string; weekdayNumber: number; weekdayLabel: string; bookings: number; definitiveBookings: number; uniqueSchools: number; students: number; averageStudentsPerBooking: number; dayProgramBookings: number; morningProgramBookings: number; programs: string[] }
export interface WeekdayBucket { weekdayNumber: number; weekdayKey: string; weekdayLabel: string; bookings: number; definitiveBookings: number; uniqueVisitDates: number; uniqueSchools: number; students: number; averageStudentsPerVisitDate: number; averageStudentsPerBooking: number; averageBookingsPerVisitDate: number; averageSchoolsPerVisitDate: number; dayProgramBookings: number; morningProgramBookings: number; programLabel: string }
export interface TopDay { rank: number; date: string; weekday: string; bookings: number; uniqueSchools: number; students: number }
export interface SchoolOccupancyCategory { key: "one" | "two" | "threePlus" | "unknown"; label: string; schoolCountMinimum: number | null; schoolCountMaximum: number | null; visitDateCount: number; percentageOfBookedVisitDates: number; bookingCount: number; studentCount: number; averageStudentsPerVisitDate: number; averageBookingsPerVisitDate: number; isDataQualityCategory: boolean }
export interface SchoolOccupancyAnalysis { totalBookedVisitDates: number; averageSchoolsPerVisitDate: number; categories: SchoolOccupancyCategory[]; context: { definition: string; schoolIdentity: string }; overCapacityVisitDateCount: number }
export interface BookingAnalyticsResponse {
  criteria: AnalyticsCriteria | null; dateBounds: { minDate: string | null; maxDate: string | null };
  summary: {
    activeBookings: number; confirmedBookings: number; optionBookings: number; rejectedBookings: number;
    plannedStudents: number; uniqueSchools: number; uniqueVisitDays: number;
    averageStudentsPerActiveBooking: number; averageStudentsPerVisitDay: number; rejectionPercentage: number;
  };
  monthlyTrend: AnalyticsRow[]; sectorDistribution: AnalyticsRow[]; programDistribution: AnalyticsRow[];
  choiceModuleDistribution: AnalyticsRow[];
  compositionDistribution: {
    selectionBookings: number; voBookings: number; oneLevel: number; multipleLevels: number;
    oneGroup: number; twoGroups: number; threeOrMoreGroups: number; averageGroups: number; levelPopulationLabel: string;
  };
  weekdayDistribution: AnalyticsRow[]; busiestVisitDates: AnalyticsRow[];
  studentCountAnalysis: StudentCountAnalysis; cateringAnalysis: CateringAnalysis;
  yearlyAnalysis: YearlyAnalysis;
  seasonalityAnalysis: {
    monthlyBuckets: MonthlyBucket[]; weekdayBuckets: WeekdayBucket[]; visitDateBuckets: VisitDateBucket[];
    availableMetricsByView: { monthly: Record<MonthlySeasonMetric, string>; weekday: Record<WeekdaySeasonMetric, string> }; summary: { bookings: number; students: number; uniqueVisitDates: number }; topDays: TopDay[]; schoolOccupancy: SchoolOccupancyAnalysis;
  };
  newSchoolsByMonth: Array<{ month: string; label: string; count: number }>;
  capacityByMonth: Array<{
    month: string; label: string; availableDays: number;
    students: { actual: number; capacity: number; percentage: number | null };
    bookingSlots: { actual: number; capacity: number; percentage: number | null };
  }>;
}
