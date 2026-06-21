import type { BoekingBeleidApiResponse } from "../../../types/booking/BookingPolicyTypes";
import { fetchBookingPolicyRules } from "../../../services/api/bookingPolicyApi";
import {
  AgendaAvailabilityDetail,
  AgendaDayInfo,
  AgendaVisualKind,
  DisabledDateDetail,
} from "../../../types/booking/BookingDateType";
import { Weekday } from "../../../types/booking/BookingProgramConfigTypes";

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

export function getDayFromDate(date: Date): number {
  return date.getDay() === 0
    ? 7
    : date.getDay();
}

export function getIsoWeekdayFromYmd(value: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return null;
  }

  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(year, month - 1, day);
  const jsDay = date.getDay();

  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return jsDay === 0 ? 7 : jsDay;
}
export function startOfLocalDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

export function isPastCalendarDate(date: Date): boolean {
  return startOfLocalDay(date).getTime() < startOfLocalDay(new Date()).getTime();
}

export function isWeekday(day: number): day is Weekday {
  return [1, 2, 3, 4, 5].includes(day)
};

export function getIsValidWeekDayFromDate(dateString: string): boolean {
  const weekday = getIsoWeekdayFromYmd(dateString);
  if (weekday === null) return false;
  return isWeekday(weekday);
}

const BookingPolicy =
  (await fetchBookingPolicyRules()) as BoekingBeleidApiResponse;

export const MAX_STUDENTS_PER_BOOKABLE_DAY =
  BookingPolicy.limieten.maxStudentenTotaal;

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
  } = params;

  /**
   * Belangrijk:
   * Eerst controleren of de datum in het verleden ligt.
   * Anders kan een oude dag in de actieve maand alsnog als boekbaar tonen.
   */
  if (isPastCalendarDate(dateObj)) {
    return {
      date,
      status: "not_bookable",
      reason: "past",
      label: "Datum voorbij",
      description:
        "Deze dag ligt achter ons. Kies een toekomstige datum voor jullie onderwijsbezoek.",
    };
  }

  if (isWeekendDate(dateObj)) {
    return {
      date,
      status: "not_bookable",
      reason: "weekend",
      label: "Weekend",
      description:
        disabledDetail?.reden ??
        "In het weekend ontvangen we geen onderwijsbezoeken. Kies een schooldag voor jullie klas.",
    };
  }

  if (disabledDetail?.type === "school_vacation") {
    return {
      date,
      status: "not_bookable",
      reason: "school_vacation",
      label: "Schoolvakantie",
      description:
        disabledDetail.reden ??
        "Deze datum valt in een schoolvakantie. Kies een andere dag voor jullie leerzame bezoek.",
    };
  }

  if (disabledDetail?.type === "manual") {
    return {
      date,
      status: "not_bookable",
      reason: "manual",
      label: "Niet ingepland",
      description:
        disabledDetail.reden ??
        "Deze datum is door de planner uitgezet en kan daarom niet geboekt worden.",
    };
  }

  if (disabledByDateList) {
    return {
      date,
      status: "not_bookable",
      reason: "manual",
      label: "Niet ingepland",
      description:
        "Deze datum is door de planner op onbeschikbaar gezet. Kies een andere bezoekdag.",
    };
  }

  /**
   * Na disabled-mogelijkheden kijken we naar boekbare dagen met capaciteit.
   */
  const rawAvailableStudents =
    availabilityDetail?.availableStudents ?? MAX_STUDENTS_PER_BOOKABLE_DAY;

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
      description:
        "Alle plekken voor onderwijsbezoeken zijn op deze datum al gereserveerd.",
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

  if (info.reason === "past") {
    return "disabled_past";
  }

  return "disabled_weekend";
}

export function getAgendaDayClass(info: AgendaDayInfo): string {
  const visualKind = getAgendaVisualKind(info);

  if (visualKind === "bookable_full") {
    return "geo-bookable-full";
  }

  if (visualKind === "bookable_limited") {
    return "geo-bookable-limited";
  }

  if (visualKind === "fully_booked") {
    return "geo-fully-booked";
  }

  if (visualKind === "disabled_manual") {
    return "geo-disabled-manual";
  }

  if (visualKind === "disabled_school_vacation") {
    return "geo-disabled-school-vacation";
  }

  if (visualKind === "disabled_past") {
    return "geo-disabled-past";
  }

  return "geo-disabled-weekend";
}

export function getAgendaInfoTitle(info: AgendaDayInfo): string {
  if (info.status === "not_bookable") {
    return info.label;
  }

  if (info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY) {
    return "Bezoekdag beschikbaar";
  }

  return "Nog plek voor je klas";
}

export function getAgendaInfoDescription(info: AgendaDayInfo): string {
  if (info.status === "not_bookable") {
    return info.description;
  }

  if (info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY) {
    return `Er is nog volledige capaciteit voor maximaal ${MAX_STUDENTS_PER_BOOKABLE_DAY} leerlingen.`;
  }

  return `Er is nog plek voor maximaal ${info.availableStudents} leerlingen.`;
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

    titleEl.textContent = "Kies jullie bezoekdag";
    descriptionEl.textContent =
      "Groen betekent beschikbaar, lichtgroen beperkt beschikbaar, rood volgeboekt en grijs/blauw niet te boeken.";

    infoDiv.replaceChildren(titleEl, descriptionEl);
    return;
  }

  const visualKind = getAgendaVisualKind(info);

  infoDiv.dataset.kind = visualKind;
  titleEl.textContent = getAgendaInfoTitle(info);

  if (info.status === "bookable") {
    const capacityText =
      info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY
        ? MAX_STUDENTS_PER_BOOKABLE_DAY
        : info.availableStudents;

    const capacityEl = document.createElement("b");
    capacityEl.className = "geo-flatpickr-info__capacity";
    capacityEl.textContent = String(capacityText);

    const prefix =
      info.availableStudents >= MAX_STUDENTS_PER_BOOKABLE_DAY
        ? "Volledige capaciteit: maximaal "
        : "Beperkte capaciteit: nog maximaal ";

    descriptionEl.append(prefix, capacityEl, " leerlingen");
  } else {
    descriptionEl.textContent = getAgendaInfoDescription(info);
  }

  infoDiv.replaceChildren(titleEl, descriptionEl);
}