import { fetchBookingProgramConfigValues } from "../../../services/api/bookingProgramConfigApi";
import type { BookingProgramConfigData } from "../../../types/booking/BookingProgramConfigTypes";

export const BookingProgramData = await fetchBookingProgramConfigValues() as BookingProgramConfigData;