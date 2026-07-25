export type BookingVisitDateCalendarState = "available" | "warning" | "override_required" | "blocked" | "past";
export interface BookingVisitDateCalendarReason { code:string;title:string;description:string;severity:string;overridable:boolean }
export interface BookingVisitDateCalendarCapacity { confirmedSchools:number;schoolLimit:number;confirmedStudents:number;studentLimit:number;confirmedProgramStudents:number;programStudentLimit:number|null }
export interface BookingVisitDateCalendarDay { date:string;state:BookingVisitDateCalendarState;selectable:boolean;isCurrentVisitDate:boolean;reasons:BookingVisitDateCalendarReason[];capacity:BookingVisitDateCalendarCapacity }
export interface BookingVisitDateCalendar { bookingId:number;status:string;program:string;programLabel:string;currentVisitDate:string;days:BookingVisitDateCalendarDay[] }
