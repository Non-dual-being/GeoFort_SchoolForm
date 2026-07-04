export type DisabledReason = "manual" | "school_vacation" | "weekend" | "past";

type AgendaNotBookableReason =
  | DisabledReason
  | "fully_booked"
  | "max_schools_reached";

export type AgendaAvailabilityStatus =
  | "available"
  | "limited"
  | "fully_booked";

export type AgendaCapacityConfig = {
  maxStudentsTotal: number;
  maxSchoolsPerDay: number;
};

export type AgendaAvailabilityDetail = {
  datum: string;

  /**
   * Deze blijft verplicht, omdat je frontend hier nu al op rekent.
   */
  availableStudents: number;

  /**
   * Deze velden mogen voorlopig optioneel blijven.
   * Zodra de backend rijkere availabilityDetails terugstuurt,
   * kun je ze verplicht maken.
   */
  bookedSchools?: number;
  bookedStudents?: number;
  remainingSchoolSlots?: number;

  maxSchoolsPerDay?: number;
  maxStudentsTotal?: number;

  status?: AgendaAvailabilityStatus;
};

export type DisabledDateDetail = {
  datum: string;
  type: DisabledReason;
  reden: string | null;
};

export type DisabledDatesData = {
  minDate: string;
  maxDate: string;
  disabledDates: string[];
  details: DisabledDateDetail[];
};

export type fullDatesInfo = DisabledDatesData & {
  availabilityDetails?: AgendaAvailabilityDetail[];

  /**
   * Nieuw:
   * Dit maakt je frontend dashboard-proof.
   * Later kan de backend deze limieten uit databasebeheer halen.
   */
  capacity?: AgendaCapacityConfig;
};

export type DisabledDatesApiResponse = {
  ok: true;
  data: DisabledDatesData;
};

export type AgendaDayInfo =
  | {
      date: string;
      status: "bookable";

      availableStudents: number;
      bookedSchools: number;
      bookedStudents: number;
      remainingSchoolSlots: number;

      maxSchoolsPerDay: number;
      maxStudentsTotal: number;
    }
  | {
      date: string;
      status: "not_bookable";
      reason: AgendaNotBookableReason;
      label: string;
      description: string;
    };

export type AgendaVisualKind =
  | "bookable_full"
  | "bookable_limited"
  | "fully_booked"
  | "disabled_manual"
  | "disabled_school_vacation"
  | "disabled_weekend"
  | "disabled_past";