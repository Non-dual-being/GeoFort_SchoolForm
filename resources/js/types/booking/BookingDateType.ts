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

