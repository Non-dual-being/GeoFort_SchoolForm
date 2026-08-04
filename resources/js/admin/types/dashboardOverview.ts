export interface DashboardOverviewBooking {
  id: number;
  visitDate: string | null;
  schoolName: string;
  programKey: string;
  programLabel: string;
  sectorKey: string;
  sectorLabel: string;
  studentCount: number | null;
  expired: boolean;
}

export interface DashboardOverviewData {
  generatedForDate: string;
  timezone: string;
  options: { total: number; items: DashboardOverviewBooking[] };
  nextOption: { visitDate: string | null; items: DashboardOverviewBooking[] };
  currentMonth: {
    year: number; month: number; total: number; confirmed: number; option: number;
    rejected: number; students: number; studentsPrimary: number; studentsSecondary: number;
  };
}
