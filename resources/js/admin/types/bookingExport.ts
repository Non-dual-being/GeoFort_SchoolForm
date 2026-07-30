export interface BookingExportBounds {
  minDate: string | null;
  maxDate: string | null;
}

export interface BookingExportPeriod {
  requestedStartDate: string;
  requestedEndDate: string;
  effectiveStartDate: string | null;
  effectiveEndDate: string | null;
  availableMinDate: string | null;
  availableMaxDate: string | null;
  hasOverlap: boolean;
  normalized: boolean;
  sortLabel: string;
}

export interface BookingExportSectorSummary {
  key: string;
  label: string;
  students: number;
}

export interface BookingExportProgramSummary {
  key: string;
  label: string;
  bookings: number;
  percentage: number;
  students: number;
  averageStudents: number;
}

export interface BookingExportSummary {
  requestStats: {
    total: number;
    status: {
      confirmed: number;
      option: number;
      rejected: number;
      other: number;
    };
    uniqueSchools: number;
    visitDays: number;
  };
  studentStats: {
    totalInSelection: number;
    planned: number;
    confirmed: number;
    option: number;
    averagePerActiveBooking: number;
    sectors: BookingExportSectorSummary[];
  };
  programStats: {
    activeBookings: number;
    day: BookingExportProgramSummary;
    morning: BookingExportProgramSummary;
    maximumStudentsOneVisitDay: number;
    choiceModules: {
      eligibleBookings: number;
      recordedChoices: number;
      distribution: Array<{ label: string; bookings: number; percentage: number }>;
    };
  };
  compositionStats: {
    selectionBookings: number;
    voBookings: number;
    levels: {
      one: { bookings: number; percentage: number };
      multiple: { bookings: number; percentage: number };
    };
    groups: {
      one: { bookings: number; percentage: number };
      two: { bookings: number; percentage: number };
      threeOrMore: { bookings: number; percentage: number };
      averagePerBooking: number;
    };
  };
  activeStatusLabels: string[];
}

export interface BookingExportSummaryResponse {
  period: BookingExportPeriod;
  summary: BookingExportSummary;
}
