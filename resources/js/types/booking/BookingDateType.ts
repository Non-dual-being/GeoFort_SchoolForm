
export type DisabledReason = "manual" | "school_vacation" | "weekend" | "past";
type AgendaNotBookableReason = DisabledReason | "fully_booked";

export type AgendaAvailabilityDetail = {
  datum: string,
  availableStudents: number
}

export type DisabledDateDetail = {
    datum: string;
    type: DisabledReason;
    reden: string | null;
}

export type DisabledDatesData = {
        minDate: string;
        maxDate: string;
        disabledDates: string[];
        details: DisabledDateDetail[];
}

export type fullDatesInfo =  DisabledDatesData & {
    availabilityDetails?: AgendaAvailabilityDetail[];
}

/**
 * de array mag ook leeg zijn, metv [] zeg je of een lege array of arrat van dat type
 */

export type DisabledDatesApiResponse = {
    ok: true;
    data: DisabledDatesData
};

export type AgendaDayInfo =
  | {
      date: string;
      status: "bookable";
      availableStudents: number;
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