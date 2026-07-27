import type {BookingRuleOverrideRequest,BookingValidationIssue} from "./bookingStatus";

export interface EducationSelectionValue{sector:string;selectedLevels:string[];selectedGroupsByLevel:Record<string,string[]>}
export interface ProgramConfigurationExpected{status:string;visitDate:string;program:string;studentCount:number;educationSelection:EducationSelectionValue;choiceModule:string|null}
export interface ProgramConfigurationProposed{program:string;studentCount:number|null;educationSelection:EducationSelectionValue;choiceModule:string|null}
export interface BookingProgramConfigurationRequest{bookingId:number;expected:ProgramConfigurationExpected;proposed:ProgramConfigurationProposed;overrides:BookingRuleOverrideRequest[]}
export type BookingProgramConfigurationCode="SUCCESS"|"NO_PROGRAM_CONFIGURATION_CHANGE"|"BOOKING_NOT_FOUND"|"PROGRAM_CONFIGURATION_CONFLICT"|"INVALID_REQUEST"|"INVALID_PROGRAM_CONFIGURATION"|"OVERRIDE_REQUIRED"|"INVALID_OVERRIDE_REQUEST"|"OVERRIDE_NOT_ALLOWED"|"OVERRIDE_PERMISSION_DENIED"|"DATABASE_ERROR";
export interface BookingProgramConfigurationResponse{ok:boolean;code:BookingProgramConfigurationCode;bookingId:number;current:ProgramConfigurationExpected|null;validationIssues:BookingValidationIssue[];overriddenRules:Array<{ruleCode:string}>;overrideCount:number;changeHistoryId:number|null}
