export type DisabledDateType = "manual" | "weekend" | "school_vacation";

export type DisabledDateDetail = {
    datum: string;
    type: DisabledDateType;
    reden: string | null;
}

export type DisabledDatesApiResponse = {
    ok: true;
    data: {
        minDate: string;
        maxDate: string;
        disabledDates: string[];
        details: DisabledDateDetail[];
    };
};

export type CalendarDateKind = {
    "bookable" :      "available_full" | "available_limited" ,
    "disabled" :      "school_vacation" | "weekend" | "manual_blocked" | "fully_booked" 
};



