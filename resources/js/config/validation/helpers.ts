import { BookingProgramData } from "../../config/booking/infopanel/programdata";
import type { SchoolSectorKey } from "../../types/booking/BookingProgramConfigTypes";

export function isValidSchoolSector(sector: string): sector is SchoolSectorKey {
  return Object.hasOwn(BookingProgramData.schoolTypesByKey, sector);
}