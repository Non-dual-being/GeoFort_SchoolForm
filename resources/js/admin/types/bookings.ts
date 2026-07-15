export interface DashboardBookingListItem {
  id: number;
  status: string;
  visitDate: string;
  schoolName: string;
  city: string;
  sectorKey: string;
  sectorLabel: string;
  programKey: string;
  programLabel: string;
  moduleKey: string | null;
  moduleLabel: string;
  studentCount: number | null;
  contactPersonName: string;
}

export interface DashboardBookingPagination {
  currentPage: number;
  perPage: number;
  totalItems: number;
  totalPages: number;
  from: number;
  to: number;
}

export interface DashboardBookingFilters {
  search: string;
  status: string;
  sector: string;
  program: string;
  module: string;
  dateFrom: string;
  dateTo: string;
  page: number;
}

export interface DashboardBookingFilterOption {
  value: string;
  label: string;
}

export interface DashboardBookingFilterOptions {
  statuses: DashboardBookingFilterOption[];
  sectors: DashboardBookingFilterOption[];
  programs: DashboardBookingFilterOption[];
  modules: DashboardBookingFilterOption[];
}

export interface DashboardBookingPageResponse {
  items: DashboardBookingListItem[];
  pagination: DashboardBookingPagination;
  filters: DashboardBookingFilterOptions;
}
