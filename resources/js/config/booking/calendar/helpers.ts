import type {
  AgendaAvailabilityDetail,
  AgendaCapacityConfig,
  AgendaDayInfo,
  AgendaVisualKind,
  DisabledDateDetail,
} from "../../../types/booking/BookingDateType";
import { disabledDateLabel } from "../../../shared/disabledDatePresentation";

import type { Weekday } from "../../../types/booking/BookingProgramConfigTypes";

export const DEFAULT_AGENDA_CAPACITY: AgendaCapacityConfig = {
  maxStudentsTotal: 160,
  maxSchoolsPerDay: 2,
};

export type CalendarInfoDiv = HTMLDivElement | null;

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
  return date.getDay() === 0 ? 7 : date.getDay();
}

function parseDateParts(dateString: string): {
  year: number;
  month: number;
  day: number;
} | null {
  const [yearRaw, monthRaw, dayRaw] = dateString.split("-");

  if (!yearRaw || !monthRaw || !dayRaw) {
    return null;
  }

  const year = Number(yearRaw);
  const month = Number(monthRaw);
  const day = Number(dayRaw);

  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
    return null;
  }

  return { year, month, day };
}

export function getIsoWeekdayFromYmd(value: string): number | null {
  const parts = parseDateParts(value);

  if (!parts) {
    return null;
  }

  const date = new Date(parts.year, parts.month - 1, parts.day);
  const jsDay = date.getDay();

  return jsDay === 0 ? 7 : jsDay;
}


export function startOfLocalDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

export function isPastCalendarDate(date: Date): boolean {
  return startOfLocalDay(date).getTime() < startOfLocalDay(new Date()).getTime();
}

export function isWeekday(day: number): day is Weekday {
  return [1, 2, 3, 4, 5].includes(day);
}

export function getIsValidWeekDayFromDate(dateString: string): boolean {
  const weekday = getIsoWeekdayFromYmd(dateString);

  if (weekday === null) {
    return false;
  }

  return isWeekday(weekday);
}

function normalizeCapacity(
  capacity?: Partial<AgendaCapacityConfig>,
): AgendaCapacityConfig {
  const maxStudentsTotal =
    typeof capacity?.maxStudentsTotal === "number"
      ? capacity.maxStudentsTotal
      : DEFAULT_AGENDA_CAPACITY.maxStudentsTotal;

  const maxSchoolsPerDay =
    typeof capacity?.maxSchoolsPerDay === "number"
      ? capacity.maxSchoolsPerDay
      : DEFAULT_AGENDA_CAPACITY.maxSchoolsPerDay;

  return {
    maxStudentsTotal: Math.max(0, maxStudentsTotal),
    maxSchoolsPerDay: Math.max(0, maxSchoolsPerDay),
  };
}

function clampNumber(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(value, max));
}

function getAvailableStudentsForDay(params: {
  availabilityDetail?: AgendaAvailabilityDetail;
  capacity: AgendaCapacityConfig;
}): number {
  const { availabilityDetail, capacity } = params;

  const rawAvailableStudents =
    availabilityDetail?.availableStudents ?? capacity.maxStudentsTotal;

  return clampNumber(
    rawAvailableStudents,
    0,
    capacity.maxStudentsTotal,
  );
}

function getBookedSchoolsForDay(
  availabilityDetail?: AgendaAvailabilityDetail,
): number {
  return Math.max(0, availabilityDetail?.bookedSchools ?? 0);
}

function getBookedStudentsForDay(
  availabilityDetail?: AgendaAvailabilityDetail,
): number {
  return Math.max(0, availabilityDetail?.bookedStudents ?? 0);
}

function getRemainingSchoolSlotsForDay(params: {
  availabilityDetail?: AgendaAvailabilityDetail;
  capacity: AgendaCapacityConfig;
}): number {
  const { availabilityDetail, capacity } = params;

  const rawRemainingSchoolSlots =
    availabilityDetail?.remainingSchoolSlots ?? capacity.maxSchoolsPerDay;

  return clampNumber(
    rawRemainingSchoolSlots,
    0,
    capacity.maxSchoolsPerDay,
  );
}

export function createAgendaDayInfo(params: {
  date: string;
  dateObj: Date;
  disabledByDateList: boolean;
  disabledDetail?: DisabledDateDetail;
  availabilityDetail?: AgendaAvailabilityDetail;
  capacity?: Partial<AgendaCapacityConfig>;
}): AgendaDayInfo {
  const {
    date,
    dateObj,
    disabledByDateList,
    disabledDetail,
    availabilityDetail,
  } = params;

  const capacity = normalizeCapacity({
    maxStudentsTotal:
      availabilityDetail?.maxStudentsTotal ?? params.capacity?.maxStudentsTotal,
    maxSchoolsPerDay:
      availabilityDetail?.maxSchoolsPerDay ?? params.capacity?.maxSchoolsPerDay,
  });

  /**
   * Eerst vaste blokkades.
   * Capaciteit is pas relevant als de dag in principe boekbaar is.
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
      label: disabledDateLabel("weekend"),
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
      label: disabledDateLabel("school_vacation"),
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
      label: disabledDateLabel("manual"),
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
      label: disabledDateLabel("manual"),
      description:
        "Deze datum is door de planner op onbeschikbaar gezet. Kies een andere bezoekdag.",
    };
  }

  /**
   * Daarna capaciteit.
   */
  const bookedSchools = getBookedSchoolsForDay(availabilityDetail);
  const bookedStudents = getBookedStudentsForDay(availabilityDetail);

  const remainingSchoolSlots = getRemainingSchoolSlotsForDay({
    availabilityDetail,
    capacity,
  });

  const availableStudents = getAvailableStudentsForDay({
    availabilityDetail,
    capacity,
  });

  if (
    availabilityDetail?.status === "fully_booked" ||
    remainingSchoolSlots <= 0
  ) {
    return {
      date,
      status: "not_bookable",
      reason: "max_schools_reached",
      label: "Volgeboekt",
      description:
        "Er staan al maximaal twee scholen ingepland op deze datum. Kies een andere bezoekdag.",
    };
  }

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
    bookedSchools,
    bookedStudents,
    remainingSchoolSlots,
    maxSchoolsPerDay: capacity.maxSchoolsPerDay,
    maxStudentsTotal: capacity.maxStudentsTotal,
  };
}

export function getAgendaVisualKind(info: AgendaDayInfo): AgendaVisualKind {
  if (info.status === "bookable") {
    return info.availableStudents >= info.maxStudentsTotal
      ? "bookable_full"
      : "bookable_limited";
  }

  if (
    info.reason === "fully_booked" ||
    info.reason === "max_schools_reached"
  ) {
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

  if (info.availableStudents >= info.maxStudentsTotal) {
    return "Bezoekdag beschikbaar";
  }

  return "Beschikbaar met beperkte capaciteit";
}

export function getAgendaInfoDescription(info: AgendaDayInfo): string {
  if (info.status === "not_bookable") {
    if (
      info.reason === "fully_booked" ||
      info.reason === "max_schools_reached"
    ) {
      return "Deze datum is volgeboekt en kan niet gekozen worden.";
    }

    return info.description;
  }

  if (info.availableStudents >= info.maxStudentsTotal) {
    return `Er is nog volledige capaciteit voor maximaal ${info.maxStudentsTotal} leerlingen.`;
  }

  const schoolSlotText =
    info.remainingSchoolSlots === 1
      ? "Er kan nog één school boeken."
      : `Er kunnen nog ${info.remainingSchoolSlots} scholen boeken.`;

  return `Er is nog plek voor maximaal ${info.availableStudents} leerlingen. ${schoolSlotText}`;
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
      info.availableStudents >= info.maxStudentsTotal
        ? info.maxStudentsTotal
        : info.availableStudents;

    const capacityEl = document.createElement("b");
    capacityEl.className = "geo-flatpickr-info__capacity";
    capacityEl.textContent = String(capacityText);

    const prefix =
      info.availableStudents >= info.maxStudentsTotal
        ? "Volledige capaciteit: maximaal "
        : "Beperkte capaciteit: nog maximaal ";

    const suffix =
      info.remainingSchoolSlots === 1
        ? " leerlingen. Er kan nog één school boeken."
        : ` leerlingen. Er kunnen nog ${info.remainingSchoolSlots} scholen boeken.`;

    descriptionEl.append(prefix, capacityEl, suffix);
  } else {
    descriptionEl.textContent = getAgendaInfoDescription(info);
  }

  infoDiv.replaceChildren(titleEl, descriptionEl);
}
