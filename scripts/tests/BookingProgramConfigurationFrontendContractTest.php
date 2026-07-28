<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$panel=$read('resources/js/admin/components/bookings/BookingProgramPanel.vue');
$view=$read('resources/js/admin/views/DashboardBookingDetailView.vue');
$api=$read('resources/js/admin/services/dashboardBookingProgramConfigurationApi.ts');
$wizardCss=$read('resources/css/admin/wizard.css');
$choiceCss=$read('resources/css/admin/choice-controls.css');
$programCss=$read('resources/css/admin/program-wizard.css');
$validator=$read('resources/js/admin/validation/programConfigurationStepValidator.ts');
$components=[
 'resources/js/admin/components/wizard/AdminWizardStepper.vue',
 'resources/js/admin/components/wizard/AdminWizardActionBar.vue',
 'resources/js/admin/components/form/AdminContextCard.vue',
 'resources/js/admin/components/form/AdminChoiceCard.vue',
 'resources/js/admin/components/form/AdminChoiceCardGrid.vue',
 'resources/js/admin/components/form/AdminConfirmationControl.vue',
 'resources/js/admin/components/form/AdminNumberControl.vue',
 'resources/js/admin/components/form/AdminOptionChip.vue',
 'resources/js/admin/components/form/AdminInlineNotice.vue',
 'resources/js/admin/components/form/AdminSelectionHint.vue',
];
$steps=[
 'resources/js/admin/components/bookings/program/BookingProgramChoiceStep.vue',
 'resources/js/admin/components/bookings/program/BookingProgramStudentStep.vue',
 'resources/js/admin/components/bookings/program/BookingProgramEducationStep.vue',
 'resources/js/admin/components/bookings/program/BookingProgramModuleStep.vue',
 'resources/js/admin/components/bookings/program/BookingProgramReviewStep.vue',
];
foreach([...$components,...$steps] as $file)$assert(is_file($root.'/'.$file),"Component ontbreekt: {$file}");
foreach(['AdminWizardStepper','AdminWizardActionBar','AdminContextCard','BookingProgramChoiceStep','BookingProgramStudentStep','BookingProgramEducationStep','BookingProgramModuleStep','BookingProgramReviewStep'] as $name)$assert(str_contains($panel,$name),"Panel gebruikt {$name} niet.");
$choice=$read($components[3]);$confirmation=$read($components[5]);$number=$read($components[6]);$stepper=$read($components[0]);$education=$read($steps[2]);$module=$read($steps[3]);$action=$read($components[1]);$review=$read($steps[4]);
$optionChip=$read($components[7]);$selectionHint=$read($components[9]);
$assert((bool)preg_match('/type="radio"/',$choice)&&str_contains($choice,'selected')&&str_contains($choice,'disabled')&&str_contains($choice,'error'),'Choice-card mist native radio of states.');
$assert(str_contains($confirmation,'type="checkbox"')&&str_contains($confirmation,'CheckCircle2'),'Confirmationcontrol mist native checkbox of checked-icon.');
$assert(str_contains($number,'type="number"')&&str_contains($number,'inputmode="numeric"')&&str_contains($number,':min="min"')&&str_contains($number,':max="max"')&&str_contains($number,':step="step"')&&str_contains($number,'modelValue-step')&&str_contains($number,'modelValue+step')&&str_contains($number,'Math.min')&&str_contains($number,'Math.max'),'Numbercontrol mist centrale native grenzen of begrensde plus/min.');
$assert(str_contains($stepper,'aria-current')&&str_contains($stepper,'completed')&&str_contains($stepper,'upcoming')&&str_contains($stepper,'error'),'Stepper mist toegankelijke states.');
$assert(str_contains($education,'AdminOptionChip')&&str_contains($education,'type="checkbox"')&&str_contains($programCss,'admin-education-grid'),'Onderwijsgrid mist level-checkbox of chips.');
$assert(str_contains($module,'AdminChoiceCardGrid')&&str_contains($module,'v-for="module in options"'),'Modulegrid gebruikt centrale opties niet.');
$assert(!str_contains($module,':value="null"')&&!str_contains($module,'label="Geen keuzemodule"')&&str_contains($module,'!modelValue')&&str_contains($validator,'MISSING_CHOICE_MODULE'),'Keuzemodule is niet verplicht of bevat nog een geen-optie.');
$assert(str_contains($panel,'getAvailableEducationModuleOptions')&&str_contains($panel,'validateCurrentModule')&&str_contains($panel,'proposed.value.choiceModule=null'),'Admin gebruikt de publieke modulefilter of stale-reset niet.');
$assert(str_contains($education,'levelDisabled')&&str_contains($education,'groupDisabled')&&str_contains($education,':disabled=')&&str_contains($education,'selectedGroupsByLevel=Object.fromEntries'),'Onderwijslimieten, disabled states of stale groepsopschoning ontbreken.');
$assert(str_contains($education,'!props.modelValue.selectedLevels.includes(level)')&&str_contains($education,'!groups.includes(group)')&&str_contains($education,'aria-disabled')&&str_contains($education,'aria-describedby'),'Geselecteerde opties blijven niet deselecteerbaar of disabled-uitleg ontbreekt.');
$assert(str_contains($education,'rules.maxLevels')&&str_contains($education,'rules.maxGroupsPerLevel')&&!preg_match('/maxLevels\\s*[:=]\\s*[13]|maxGroupsPerLevel\\s*[:=]\\s*3/',$education),'Maximumteksten gebruiken niet uitsluitend centrale props.');
$assert(str_contains($panel,'attemptedSteps')&&str_contains($panel,'validateStep(currentStepId.value)'),'Minimumvalidatie blokkeert Volgende niet stapgericht.');
$assert(str_contains($panel,'const found = stepIssues("review")')&&str_contains($panel,'currentStepId.value = first')&&str_contains($panel,'void save(false)'),'Review valideert eerdere stappen niet vóór opslag.');
$assert(strpos($panel,'void save(false)')>strpos($panel,'const found = stepIssues("review")'),'API-opslag kan vóór lokale reviewvalidatie plaatsvinden.');
$assert(str_contains($panel,'stepIssues(id).length === 0')&&str_contains($panel,'currentStepId.value === id'),'Completed-state vereist geen geldige bevestigde stap.');
$assert(str_contains($panel,'errorSummary.value?.focus()')&&str_contains($panel,'await nextTick()'),'Lokale foutfocus ontbreekt.');
$assert(str_contains($validator,'MISSING_EDUCATION_LEVEL')&&str_contains($validator,'MISSING_EDUCATION_GROUP')&&str_contains($validator,'GROUP_WITHOUT_SELECTED_LEVEL')&&str_contains($validator,'UNKNOWN_EDUCATION_GROUP'),'Pure onderwijsstapvalidator is onvolledig.');
$assert(str_contains($validator,'STUDENT_COUNT_OUT_OF_RANGE')&&str_contains($validator,'Number.isInteger')&&str_contains($validator,'availableModules.some'),'Leerling- of modulevalidatie ontbreekt.');
$assert(str_contains($panel,'visibleSteps')&&str_contains($panel,'choiceModulesSupported')&&str_contains($panel,'currentStepId')&&str_contains($panel,':total-steps="totalSteps"'),'Dynamische wizardstappen of stabiele step-ids ontbreken.');
$assert(str_contains($panel,'proposed.value.choiceModule=null')&&str_contains($panel,'moduleChangeNotice')&&str_contains($panel,'Het Ochtendprogramma gebruikt geen keuzemodule'),'Dag naar Ochtend wist de module of informeert de planner niet.');
$assert(str_contains($validator,'if(!context.supportsChoiceModules)')&&str_contains($validator,'proposed.choiceModule===null')&&str_contains($validator,'context.confirmations.module'),'Capability-afhankelijke modulevalidatie ontbreekt.');
$assert(str_contains($review,'v-if="supportsChoiceModules"')&&str_contains($review,"emit('edit','module')"),'Review verbergt de module niet centraal of gebruikt geen step-id.');
$assert(!preg_match('/from "vue"|document\\.|querySelector/',$validator),'Pure stapvalidator bevat Vue- of DOM-logica.');
$assert(!str_contains($choiceCss,'cursor: not-allowed')&&str_contains($choiceCss,'cursor: help')&&str_contains($programCss,'admin-level-card__header-row--temporarily-disabled'),'Tijdelijk disabled selectiefeedback gebruikt een forbidden-cursor of mist helpstatus.');
$assert(str_contains($selectionHint,'CircleHelp')&&str_contains($selectionHint,'type="button"')&&str_contains($selectionHint,'aria-expanded')&&str_contains($selectionHint,'aria-controls')&&str_contains($selectionHint,'aria-label'),'Helpcomponent mist icoon, echte button of ARIA-contract.');
$assert(str_contains($selectionHint,'@pointerdown="pointerDown"')&&str_contains($selectionHint,'@click.stop="toggle"')&&str_contains($selectionHint,'@focus="focus"')&&str_contains($selectionHint,'if(!pointerInteracting)setOpen(true)')&&str_contains($selectionHint,'Escape')&&str_contains($selectionHint,'pointerdown'),'Help werkt niet betrouwbaar via tik, focus, Escape en buitenklik.');
$assert(str_contains($selectionHint,'positionPopover')&&str_contains($selectionHint,'window.innerWidth')&&str_contains($selectionHint,'window.innerHeight')&&str_contains($choiceCss,'position: fixed'),'Hint wordt niet binnen het mobiele viewport geplaatst.');
$assert(str_contains($optionChip,':disabled="disabled"')&&str_contains($optionChip,'disabledMessage')&&str_contains($optionChip,'AdminSelectionHint'),'Native disabled groepchip of specifieke helpreden ontbreekt.');
$assert(str_contains($education,'levelLimitMessage(level.label)')&&str_contains($education,'groupLimitMessage(level.label,groupLabel)'),'Specifieke niveau- of groepslabels ontbreken in disabled uitleg.');
$assert(str_contains($education,'props.rules.maxLevels')&&str_contains($education,'props.rules.maxGroupsPerLevel'),'Disabled uitleg gebruikt niet de centrale maximumprops.');
$assert(str_contains($education,'levelCountText')&&str_contains($education,'groupCountText')&&str_contains($education,'admin-selection-count--complete')&&str_contains($education,'admin-selection-count--limit'),'Reactieve normale, complete en limitstatus ontbreekt.');
$assert(str_contains($number,'atMinimum')&&str_contains($number,'atMaximum')&&str_contains($number,'Het minimum is bereikt')&&str_contains($number,'Het maximum is bereikt')&&str_contains($number,'AdminSelectionHint'),'Leerlinggrenzen missen contextuele hulp of helpactie.');
$assert(str_contains($choiceCss,'width: 2.75rem')&&str_contains($choiceCss,'height: 2.75rem'),'Help-touch target is kleiner dan 44x44 px.');
$assert(!preg_match('/primairOnderwijs|voortgezet|vmbo|havo|groep\\s*[0-9]|maxLevels|maxGroupsPerLevel/',$selectionHint),'Generieke hintcomponent bevat bookingbedrijfsregels.');
$assert(str_contains($panel,'issueStepId')&&str_contains($panel,'visibleSteps.value.find')&&str_contains($panel,'aria-live="assertive"')&&str_contains($panel,'nextTick'),'Issue-stepmapping, foutnavigatie of aria-live ontbreekt.');
$assert(str_contains($panel,'De programmaconfiguratie is bijgewerkt.')&&str_contains($view,'role="status"'),'Toegankelijke succesflash ontbreekt.');
$assert(str_contains($panel,'safeIssueText')&&!str_contains($panel,'?.description??"De programmaconfiguratie'),'Veilige issuefallback ontbreekt.');
$assert(!preg_match('/Minecraft|Klimparcours|Earth-Watch/',$panel.$education.$module),'Modulebedrijfsregels zijn in Vue gehardcodeerd.');
$assert(substr_count($action,'Configuratie opslaan')===1&&substr_count($action,'Volgende')===1&&str_contains($action,'props.step<props.totalSteps'),'Actionbar toont acties niet stapspecifiek.');
$assert(str_contains($review,'Bezoekdatum')&&str_contains($review,'Schoolsector')&&str_contains($review,'Status')&&str_contains($review,"emit('edit'"),'Reviewstep is onvolledig.');
$assert(str_contains($panel,'PROGRAM_CONFIGURATION_CONFLICT')&&str_contains($panel,'uw voorgestelde waarden zijn behouden')&&str_contains($panel,'BookingRuleOverrideDialog'),'Conflictbehoud of overrideflow ontbreekt.');
$assert(str_contains($panel,'expected:expected.value')&&str_contains($panel,'proposed:proposed.value')&&str_contains($api,'update-booking-program-configuration.php'),'Aggregatepayload of endpoint is gewijzigd.');
foreach($steps as $file)$assert(!preg_match('/\bfetch\s*\(|updateDashboardBooking/',$read($file)),"Presentational child doet API-call: {$file}");
$generic=implode("\n",array_map($read,$components));
$assert(!preg_match('/Ochtendprogramma|Dagprogramma|PROGRAM_WEEKDAY_MISMATCH|primairOnderwijs/',$generic),'Generieke controls bevatten bookingregels.');
$assert(str_contains($choiceCss,'repeat(auto-fit, minmax(min(100%, 15rem), 1fr))')&&str_contains($programCss,'repeat(3, minmax(0, 1fr))')&&str_contains($programCss,'repeat(2, minmax(0, 1fr))')&&str_contains($programCss,'minmax(0, 1fr)')&&str_contains($wizardCss,'@media (max-width: 37.5rem)'),'Responsive gridcontract ontbreekt.');
$assert(str_contains($view,'JSON.stringify(booking.education.selections)'),'Kalenderinvalidatie ontbreekt.');
fwrite(STDOUT,"OK: programmaconfiguratie frontendcontract geslaagd.\n");
