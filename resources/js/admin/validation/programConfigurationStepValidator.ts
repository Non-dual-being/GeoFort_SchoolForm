import type {EducationSelectionValue,ProgramConfigurationProposed} from "../types/bookingProgramConfiguration";

export type ProgramConfigurationFrontendIssue={
  step:number;
  field:string;
  code:string;
  message:string;
  level?:string;
};

export type ProgramConfigurationValidationContext={
  schoolSector:string;
  weekday:number;
  options:Array<{key:string;allowedSchoolTypes:string[];allowedWeekdays:number[]}>;
  selectionRules:{minLevels:number;maxLevels:number;minGroupsPerLevel:number;maxGroupsPerLevel:number};
  schoolLevels:Record<string,{label:string;groups:Record<string,string>}>;
  studentLimits:{minimum:number;maximum:number}|null;
  availableModules:Array<{key:string}>;
  confirmations:boolean[];
  weekdayAccepted:boolean;
};

function confirmationIssue(step:number,confirmed:boolean):ProgramConfigurationFrontendIssue[]{
  return confirmed?[]:[{step,field:`confirmation.${step}`,code:"STEP_CONFIRMATION_REQUIRED",message:"Bevestig deze stap voordat u verdergaat."}];
}

function validateProgram(proposed:ProgramConfigurationProposed,context:ProgramConfigurationValidationContext):ProgramConfigurationFrontendIssue[]{
  const option=context.options.find(item=>item.key===proposed.program);
  if(!proposed.program||!option)return[{step:1,field:"program",code:"MISSING_OR_UNKNOWN_PROGRAM",message:"Kies een geldig programma."}];
  if(!option.allowedSchoolTypes.includes(context.schoolSector))return[{step:1,field:"program",code:"PROGRAM_SCHOOL_SECTOR_MISMATCH",message:"Dit programma is niet beschikbaar voor deze schoolsector."}];
  if(!option.allowedWeekdays.includes(context.weekday)&&!context.weekdayAccepted)return[{step:1,field:"program",code:"PROGRAM_WEEKDAY_MISMATCH",message:"Bevestig dat u bewust afwijkt van de normale weekdag."}];
  return confirmationIssue(1,context.confirmations[0]??false);
}

function validateStudents(proposed:ProgramConfigurationProposed,context:ProgramConfigurationValidationContext):ProgramConfigurationFrontendIssue[]{
  const limits=context.studentLimits;
  if(!Number.isInteger(proposed.studentCount))return[{step:2,field:"studentCount",code:"INVALID_STUDENT_COUNT",message:"Vul een geheel leerlingenaantal in."}];
  if(!limits)return[{step:2,field:"studentCount",code:"MISSING_STUDENT_LIMITS",message:"Voor dit programma zijn geen leerlinglimieten beschikbaar."}];
  if((proposed.studentCount as number)<limits.minimum||(proposed.studentCount as number)>limits.maximum)return[{step:2,field:"studentCount",code:"STUDENT_COUNT_OUT_OF_RANGE",message:`Vul minimaal ${limits.minimum} en maximaal ${limits.maximum} leerlingen in.`}];
  return confirmationIssue(2,context.confirmations[1]??false);
}

function validateEducation(selection:EducationSelectionValue,context:ProgramConfigurationValidationContext):ProgramConfigurationFrontendIssue[]{
  const issues:ProgramConfigurationFrontendIssue[]=[];
  const rules=context.selectionRules;
  const uniqueLevels=[...new Set(selection.selectedLevels)];
  if(uniqueLevels.length<rules.minLevels)issues.push({step:3,field:"educationSelection.selectedLevels",code:"MISSING_EDUCATION_LEVEL",message:rules.minLevels===1?"Selecteer minimaal één niveau.":`Selecteer minimaal ${rules.minLevels} niveaus.`});
  if(uniqueLevels.length>rules.maxLevels)issues.push({step:3,field:"educationSelection.selectedLevels",code:"LEVEL_SELECTION_LIMIT_EXCEEDED",message:`U kunt maximaal ${rules.maxLevels} niveaus selecteren.`});
  for(const level of uniqueLevels){
    const configured=context.schoolLevels[level];
    if(!configured){issues.push({step:3,field:"educationSelection.selectedLevels",code:"UNKNOWN_EDUCATION_LEVEL",message:"Een geselecteerd niveau hoort niet bij deze schoolsector.",level});continue;}
    const groups=[...new Set(selection.selectedGroupsByLevel[level]??[])];
    if(groups.length<rules.minGroupsPerLevel)issues.push({step:3,field:`educationSelection.selectedGroupsByLevel.${level}`,code:"MISSING_EDUCATION_GROUP",message:rules.minGroupsPerLevel===1?"Selecteer binnen ieder gekozen niveau minimaal één groep.":`Selecteer binnen ieder gekozen niveau minimaal ${rules.minGroupsPerLevel} groepen.`,level});
    if(groups.length>rules.maxGroupsPerLevel)issues.push({step:3,field:`educationSelection.selectedGroupsByLevel.${level}`,code:"GROUP_SELECTION_LIMIT_EXCEEDED",message:`U kunt binnen dit niveau maximaal ${rules.maxGroupsPerLevel} groepen selecteren.`,level});
    if(groups.some(group=>!configured.groups[group]))issues.push({step:3,field:`educationSelection.selectedGroupsByLevel.${level}`,code:"UNKNOWN_EDUCATION_GROUP",message:"Een geselecteerde groep hoort niet bij dit niveau.",level});
  }
  for(const [level,groups] of Object.entries(selection.selectedGroupsByLevel)){
    if(groups.length>0&&!uniqueLevels.includes(level))issues.push({step:3,field:`educationSelection.selectedGroupsByLevel.${level}`,code:"GROUP_WITHOUT_SELECTED_LEVEL",message:"Er zijn groepen gekozen bij een niveau dat niet is geselecteerd.",level});
  }
  return issues.length?issues:confirmationIssue(3,context.confirmations[2]??false);
}

function validateModule(proposed:ProgramConfigurationProposed,context:ProgramConfigurationValidationContext):ProgramConfigurationFrontendIssue[]{
  if(context.availableModules.length===0)return[{step:4,field:"choiceModule",code:"NO_CHOICE_MODULE_AVAILABLE",message:"Voor deze combinatie is geen keuzemodule beschikbaar. Pas het programma of de onderwijsselectie aan."}];
  if(!proposed.choiceModule)return[{step:4,field:"choiceModule",code:"MISSING_CHOICE_MODULE",message:"Kies een keuzemodule die past bij het programma en de geselecteerde groepen."}];
  if(!context.availableModules.some(module=>module.key===proposed.choiceModule))return[{step:4,field:"choiceModule",code:"INVALID_CHOICE_MODULE",message:"De eerder gekozen keuzemodule past niet meer bij de nieuwe selectie. Kies een nieuwe keuzemodule."}];
  return confirmationIssue(4,context.confirmations[3]??false);
}

export function validateProgramConfigurationStep(step:number,proposed:ProgramConfigurationProposed,context:ProgramConfigurationValidationContext):ProgramConfigurationFrontendIssue[]{
  if(step===1)return validateProgram(proposed,context);
  if(step===2)return validateStudents(proposed,context);
  if(step===3)return validateEducation(proposed.educationSelection,context);
  if(step===4)return validateModule(proposed,context);
  const issues=[...validateProgram(proposed,context),...validateStudents(proposed,context),...validateEducation(proposed.educationSelection,context),...validateModule(proposed,context)];
  return issues.length?issues:confirmationIssue(5,context.confirmations[4]??false);
}
