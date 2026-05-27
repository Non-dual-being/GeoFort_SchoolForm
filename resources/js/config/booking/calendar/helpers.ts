import type { BoekingBeleidApiResponse } from "../../../types/booking/BookingPolicyTypes";
import { fetchBookingPolicyRules } from "../../../services/api/bookingPolicyApi";
import { 
  AgendaAvailabilityDetail, 
  DisabledDateDetail,
  AgendaDayInfo,
  AgendaVisualKind
} from "../../../types/booking/BookingDateType";

export function toIsoDate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

export function isWeekendDate(date: Date): boolean {
  const day = date.getDay();

  return day === 0 || day === 6;
}

const BookingPolicy = await fetchBookingPolicyRules() as BoekingBeleidApiResponse;

export const MAX_STUDENTS_PER_BOOKABLE_DAY = BookingPolicy.limieten.maxStudentenTotaal;

export type CalendarInfoDiv = HTMLDivElement | null;

export function createAgendaDayInfo(params: {
  date: string;
  dateObj: Date;
  disabledByDateList: boolean;
  disabledDetail?: DisabledDateDetail;
  availabilityDetail?: AgendaAvailabilityDetail;
}): AgendaDayInfo {
    const {
        date,
        dateObj,
        disabledByDateList,
        disabledDetail,
        availabilityDetail,
    } = params

    if (isWeekendDate(dateObj)) {
        return {
          date,
          status: "not_bookable",
          reason: "weekend",
          label: "Weekend",
          description: disabledDetail?.reden ?? "In het weekend is deze onderwijsboeking niet beschikbaar.",

        }
    }

    if (disabledDetail?.type === "school_vacation") {
        return {
          date,
          status: "not_bookable",
          reason: "school_vacation",
          label: "Schoolvakantie",
          description:
            disabledDetail.reden ?? "Deze datum valt binnen een schoolvakantie.",
        };

    }

    if (disabledDetail?.type === "manual") {
      return {
        date,
        status: "not_bookable",
        reason: "manual",
        label: "Niet beschikbaar",
        description: "Deze datum is geblokkeerd door de planner.",
      };
    }

    if (disabledByDateList) {
        return {
          date,
          status: "not_bookable",
          reason: "manual",
          label: "Niet beschikbaar",
          description: "Deze datum is niet beschikbaar.",
        };
    }
    /**Na disabled mogelijkheden nu kijken naar de boekingsdagen met capiciteit */

    //pak de details van de backend of als die ontbreekt de max ook aangeleverd door backend
    const rawAvailableStudents =
    availabilityDetail?.availableStudents ?? MAX_STUDENTS_PER_BOOKABLE_DAY;

    //pak de max van 0 of de --[min van de aangeboden aantal met falback de max van 160]
    const availableStudents = Math.max(
      0,
      Math.min(rawAvailableStudents, MAX_STUDENTS_PER_BOOKABLE_DAY),
    );

    if (availableStudents <= 0) {
      return {
        date,
        status: "not_bookable",
        reason: "fully_booked",
        label: "Volgeboekt",
        description: "Deze datum is volledig volgeboekt.",
      };
    }

    return {
      date,
      status: "bookable",
      availableStudents,
    };

}

export function getAgendaVisualKind(info: AgendaDayInfo): AgendaVisualKind {
  if (info.status === "bookable") {
    return info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY
      ? "bookable_full"
      : "bookable_limited";
  }

  if (info.reason === "fully_booked") {
    return "fully_booked";
  }

  if (info.reason === "manual") {
    return "disabled_manual";
  }

  if (info.reason === "school_vacation") {
    return "disabled_school_vacation";
  }

  return "disabled_weekend";
}

export function getAgendaInfoTitle(info: AgendaDayInfo): string {
  if (info.status === "not_bookable") {
    return info.label;
  }

  return "Datum beschikbaar voor boeking.";
}

export function getAgendaInfoDescription(info: AgendaDayInfo): string {
  if (info.status === "not_bookable") {
    return info.description;
  }

  if (info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY) {
    return `Te boeken tot max ${info.availableStudents} leerlingen.`;
  }

  return `Nog te boeken voor max ${info.availableStudents} leerlingen.`;
}


export function setCalendarInfo(
  infoDiv: CalendarInfoDiv,
  info: AgendaDayInfo | null,
): void {
  if (!infoDiv) {
    return;
  }

  const titleEl = document.createElement("strong");
  const descriptionEl = document.createElement("span");

  titleEl.className = "geo-flatpickr-info__title";
  descriptionEl.className = "geo-flatpickr-info__description";

  if (!info) {
    infoDiv.dataset.kind = "default";

    titleEl.textContent = "Kies een bezoekdatum";
    descriptionEl.textContent =
      "Donkergroen is beschikbaar. Lichtgroen is beperkt beschikbaar. Rood is volgeboekt.";

    infoDiv.replaceChildren(titleEl, descriptionEl);
    return;
  }

  const visualKind = getAgendaVisualKind(info);

  infoDiv.dataset.kind = visualKind;
  titleEl.textContent = getAgendaInfoTitle(info);

  if (info.status === "bookable") {
    const maxText =
      info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY
        ? MAX_STUDENTS_PER_BOOKABLE_DAY
        : info.availableStudents;

    const capacityEl = document.createElement("b");
    capacityEl.className = "geo-flatpickr-info__capacity";
    capacityEl.textContent = String(maxText);

    descriptionEl.append("Te boeken tot max ", capacityEl, " leerlingen");
  } else {
    descriptionEl.textContent = getAgendaInfoDescription(info);
  }

  infoDiv.replaceChildren(titleEl, descriptionEl);
}