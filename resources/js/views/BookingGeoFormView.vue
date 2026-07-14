<script setup lang="ts">
/**
 * Booking form parent
 *
 * Deze component is de orkestrator van het formulier.
 *
 * Verantwoordelijkheden:
 * - backendconfig ophalen
 * - formulier-state beheren
 * - veldnormalisatie uitvoeren
 * - formulier-validatie uitvoeren
 * - submit naar backend uitvoeren
 * - flow bewaken tussen:
 *   1. bezoekdatum
 *   2. onderwijssector
 *   3. programma: ochtend/dag
 *   4. onderwijsniveau/groepen
 *   5. later: keuzemodule
 *   6. later/nu: aantal leerlingen
 *
 * Bewust NIET hier:
 * - details van niveau/groep-validatie
 * - details van programma-filtering
 * - details van module-filtering
 *
 * Die zitten in composables/helpers.
 */

/* ==========================================================================
   Vue & icons
   ========================================================================== */

import { GraduationCap } from "lucide-vue-next";

import {
  computed,
  onMounted,
  ref,
  watch,
  type ComponentPublicInstance,
} from "vue";

/* ==========================================================================
   Components
   ========================================================================== */

import GeoBookingDateField from "./../components/form/GeoFormBookingDateField.vue";
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue";
import GeoDiscoverySelectField from "./../components/form/GeoFormDiscoveryField.vue";
import GeoFooter from "../components/layout/AppFooter.vue";
import GeoFormEducationLevelSelector from "./../components/form/GeoFormEducationLevelSelector.vue";
import GeoFormEducationTypeSelect from "../components/form/GeoFormEducationSectorSelect.vue";
import GeoFormInputField from "./../components/form/GeoFormInputField.vue";
import GeoFormProgramSelect from "../components/form/GeoFormProgramSelect.vue";
import GeoFormRadioGroup from "../components/form/GeoFormRadioGroup.vue";
import GeoFormSelectField from "../components/form/GeoFormSelectFied.vue";
import GeoInfoToggle from "../components/form/GeoInfoToggles.vue";
import BookingInfoCard from "../components/form/GeoFormBookingProgramInfoPanel.vue";
import GeoFormEducationModuleSelector from "../components/form/GeoFormEducationModuleSelector.vue";
import FormError from "./../components/form/FormLevelError.vue";
import GeoFormStudentCountField from "../components/form/GeoFormStudentCountField.vue";
import GeoFormSupervisorCountField from "../components/form/GeoFormSupervisorCountField.vue";
import GeoFormRosterPreview from "../components/form/GeoFormRosterPreview.vue";
import GeoFormFoodAndDrinkInfoPanel from "../components/form/GeoFormFoodAndDrinkInfoPanel.vue";
import GeoFormFoodAndDrinkSelectionField from "../components/form/GeoFormFoodAndDrinkSelectionField.vue";
import GeoFormPriceQuotePreview from "../components/form/GeoFormPriceQuotePreview.vue";
import GeoFormTermsAcceptanceField from "../components/form/GeoFormTermsAcceptanceField.vue";
import GeoFormTextareaField from "../components/form/GeoFormTextareaField.vue";


/* ==========================================================================
   Composables
   ========================================================================== */

import { useEducationProgram } from "../composables/useEducationProgram";
import { useEducationSelection } from "../composables/useEducationSelection";
import { useFormSubmit } from "../composables/useFormSubmit.ts";
import { useScrollIndicator } from "../composables/useScrollindicator.ts";
import { useEducationModules } from "../composables/useEducationModules";
import { useStudentCount } from "../composables/useStudentCount";
import { useSupervisorCount } from "../composables/useSupervisorCount";
import { useBookingRoster } from "../composables/useBookingRoster";
import { useFoodAndDrinkSelection } from "../composables/useFoodAndDrinkSelection";
import { useBookingPriceQuote } from "../composables/useBookingPriceQuote";

/* ==========================================================================
   API
   ========================================================================== */

import { fetchBookingProgramConfigValues } from "../services/api/bookingProgramConfigApi.ts";
import { fetchBookingPolicyRules } from "../services/api/bookingPolicyApi.ts";

/* ==========================================================================
   Form config
   ========================================================================== */

import {
  BookingFieldConfig,
  countryOptions,
  createEmptyLevelSelection,
  createInitialBookingForm,
  getPlaceHolder,
  isPhoneBookingField,
  type BaseBookingFormValues,
  type BookingFormValues,
} from "./../config/booking/BookingFields.ts";

import {
  basisFieldNames,
  bookingFieldNames,
  cjpUsageOptions,
} from "./../config/booking/BookingFieldConstants.ts";

import {
  createInitialFieldRefs,
  createInitialFlashTriggers,
  createInitialIssues,
} from "./../config/booking/BookingFormState.ts";

import { getIsValidWeekDayFromDate } from "../config/booking/calendar/helpers.ts";

/* ==========================================================================
   Validation / normalization
   ========================================================================== */

import {
  geofortDiscoverySelectOptions,
  isCountryCode,
  normalizeCjpPasnumber,
  normalizeCjpPersonName,
  normalizeEmail,
  normalizeGeoFortDiscovery,
  normalizePhoneNumber,
  normalizePostcode,
  normalizeQuestionsAndComments,
  otherOption,
  validateAll,
  validateField,
} from "./../config/validation/booking.ts";

import { isValidSchoolSector } from "../config/validation/helpers.ts";

/* ==========================================================================
   Types
   ========================================================================== */

import type {
  BookingField,
  CountryDependentField,
  InputFieldInstance,
  PhoneNumberField,
  SchoolSectorOption,
} from "../types/booking/BookingFieldTypes.ts";

import type {
  BookingProgramConfigData,
  EducationModuleKey,
  ProgramKey,
  SchoolSectorKey,
} from "../types/booking/BookingProgramConfigTypes.ts";

import type { ApiResponse } from "../types/http/ApiResponse.ts";

import type { fullDatesInfo } from "../types/booking/BookingDateType";
import type { BoekingBeleidApiResponse } from "../types/booking/BookingPolicyTypes";

/* ==========================================================================
   Global page behavior
   ========================================================================== */

/**
 * De scroll-indicator staat los van de formulierflow.
 */
useScrollIndicator(window);

/* ==========================================================================
   Component events
   ========================================================================== */

const emit = defineEmits<{
  success: [];
  "server-error": [{ message: string }];
}>();

/* ==========================================================================
   Page/API state
   ========================================================================== */

const pageVisible = ref(false);
const bookingProgramConfig = ref<BookingProgramConfigData | null>(null);
const bookingPolicy = ref<BoekingBeleidApiResponse | null>(null);
const termsUrl = "/assets/booking/documents/Algemene_Voorwaarden_GeoFort_Onderwijs.pdf";

onMounted(async () => {
  const [programConfig, policyRules] = await Promise.all([
    fetchBookingProgramConfigValues(),
    fetchBookingPolicyRules(),
  ]);

  bookingProgramConfig.value = programConfig;
  bookingPolicy.value = policyRules;

  /**
   * Eerst de startwaarde renderen, daarna pas de page-visible class toevoegen.
   * Daardoor blijft de enter-animatie betrouwbaar.
   */
  requestAnimationFrame(() => {
    pageVisible.value = true;
  });
});

/* ==========================================================================
   Core form state
   ========================================================================== */

const formValues = ref<BookingFormValues>(createInitialBookingForm());
const formIssues = ref(createInitialIssues());
const formFlashTriggers = ref(createInitialFlashTriggers());
const formFieldRefs = ref(createInitialFieldRefs());

/* ==========================================================================
   Submit state
   ========================================================================== */

const {
  state,
  formError,
  submit,
  clearFormError,
} = useFormSubmit();

/* ==========================================================================
   Basic derived state
   ========================================================================== */

/**
 * Sectoropties voor de onderwijssectorselect.
 * Deze komen uit de backendconfig.
 */
const selectorOptions = computed<SchoolSectorOption[]>(() => {
  if (!bookingProgramConfig.value) {
    return [];
  }

  return bookingProgramConfig.value.schoolTypes.map(({ label, value }) => ({
    label,
    value,
  }));
});

/**
 * Datum is geldig wanneer deze binnen de toegestane weekdagen valt.
 */
const hasValidVisitDate = computed(() => {
  return getIsValidWeekDayFromDate(formValues.value.bezoekdatum);
});

const agendaData = ref<fullDatesInfo | null>(null);

function handleAgendaDataLoaded(data: fullDatesInfo): void {
  agendaData.value = data;
}

const canShowStudentCountBase = computed(() => {
  return canShowModuleSelect.value && hasValidModuleSelection.value;
});


/**
 * Sector is geldig wanneer de waarde een bekende SchoolSectorKey is.
 */
const hasValidSchoolSector = computed(() => {
  return isValidSchoolSector(formValues.value.onderwijsSector);
});

/**
 * De actieve sector als echte SchoolSectorKey.
 *
 * Hiermee voorkom je dat child-components met "" of ongeldige strings
 * hoeven om te gaan.
 */
const currentSchoolSector = computed<SchoolSectorKey | null>(() => {
  return hasValidSchoolSector.value
    ? (formValues.value.onderwijsSector as SchoolSectorKey)
    : null;
});

/**
 * CJP-subvelden worden alleen getoond en meegestuurd wanneer de gebruiker
 * expliciet "ja" kiest.
 */
const usesCjpDiscount = computed(() => {
  return formValues.value.cjpPasGebruik === "ja";
});

/* ==========================================================================
   Program state: ochtend / dag
   ========================================================================== */

/**
 * Programma betekent hier:
 * - ochtend
 * - dag
 *
 * Let op:
 * Dit is dus NIET de lesmodule.
 *
 * De programma-keuze komt ná:
 * - bezoekdatum
 * - onderwijssector
 *
 * En vóór:
 * - onderwijsniveau/groepen
 * - keuzemodule
 */
const {
  availableProgramOptions,
  hasValidProgramSelection,
  programSelectionIssue,
  selectedProgramIsStillAvailable,

  setSelectedProgram,
  resetSelectedProgram,
} = useEducationProgram({
  formValues,
  bookingProgramConfig,
  currentSchoolSector,
  hasValidVisitDate,
});

/**
 * Losse flash-trigger voor de programmakeuze.
 *
 * Dit veld zit niet in bookingFieldNames en heeft dus een eigen trigger nodig.
 */
const programFlashTrigger = ref(0);

/* ==========================================================================
   Flow visibility
   ========================================================================== */

/**
 * Programmakeuze mag verschijnen zodra datum + sector geldig zijn.
 */
const canShowProgramSelect = computed(() => {
  return hasValidVisitDate.value && currentSchoolSector.value !== null;
});

/**
 * Onderwijsniveau/groepen mogen pas verschijnen na een geldige programma-keuze.
 */
const canShowEducationDetails = computed(() => {
  return canShowProgramSelect.value && hasValidProgramSelection.value;
});

/**
 * Later gebruiken we dit voor de lesmodule.
 *
 * Nu is dit alvast de plek waar je zegt:
 * modulekeuze mag pas na geldige datum, sector, programma,
 * niveau en groepen.
 */
const canShowModuleSelect = computed(() => {
  return (
    hasValidVisitDate.value &&
    hasValidSchoolSector.value &&
    hasValidProgramSelection.value &&
    hasValidLevelSelection.value &&
    hasValidGroupSelection.value
  );
});

/* ==========================================================================
   Education level/group state
   ========================================================================== */

/**
 * De echte level/groep-logica zit in useEducationSelection().
 *
 * De parent gebruikt alleen:
 * - beschikbare opties
 * - geselecteerde waarden
 * - validatie-uitkomsten
 * - prune-helper
 */
const {
  availableLevelsForSector,
  currentLevelSelectionRules,

  selectedLevelsForCurrentSector,
  selectedGroupsForCurrentSector,

  levelSelectionIssue,
  hasValidLevelSelection,

  groupSelectionIssues,
  hasValidGroupSelection,

  pruneGroupsForDeselectedLevels,
} = useEducationSelection({
  formValues,
  bookingProgramConfig,
  currentSchoolSector,
  canShowEducationDetails,
});

/* ==========================================================================
   Education module state
   ========================================================================== */

/**
 * Keuzemodule-logica.
 *
 * De modulekeuze komt ná:
 * - datum
 * - onderwijssector
 * - programma
 * - onderwijsniveau/groepen
 *
 * De beschikbare modules komen uit backendconfig:
 * - config.modules
 * - config.moduleFilters
 */
const {
  standardModuleOptions,
  choiceModuleOptions,
  requiresChoiceModule,

  hasValidModuleSelection,
  moduleSelectionIssue,
  selectedModuleIsStillAvailable,

  setSelectedModule,
  resetSelectedModule,
} = useEducationModules({
  formValues,
  bookingProgramConfig,
  currentSchoolSector,
  selectedLevelsForCurrentSector,
  selectedGroupsForCurrentSector,
  canShowModuleSelect,
});



const {
  canShowStudentCount,

  minStudents,
  availableStudentsForDate,
  effectiveMaxStudents,

  studentCountIssue,
  hasValidStudentCount,

  studentCountLimitText,
  studentCountHelpText,
  studentCountPlaceholder,
} = useStudentCount({
  formValues,
  bookingProgramConfig,
  currentSchoolSector,
  canShowStudentCountBase,
  agendaData,
});

const studentCountFlashTrigger = ref(0);
const backendStudentCountIssue = ref<string | null>(null);

const visibleStudentCountIssue = computed(() => {
  return backendStudentCountIssue.value ?? studentCountIssue.value;
});

const canShowSupervisorCountBase = computed(() => {
  return hasValidStudentCount.value;
});

const {
  canShowSupervisorCount,
  maxSupervisors,
  freeSupervisorCount,

  supervisorCountIssue,
  hasValidSupervisorCount,

  supervisorCountPlaceholder,
  supervisorCountHelpText,
  supervisorCountLimitText,
} = useSupervisorCount({
  formValues,
  bookingPolicy,
  canShowSupervisorCountBase,
});

const supervisorCountFlashTrigger = ref(0);
const backendSupervisorCountIssue = ref<string | null>(null);
const supervisorCountTouched = ref(false);

const visibleSupervisorCountIssue = computed(() => {
  if (backendSupervisorCountIssue.value) {
    return backendSupervisorCountIssue.value;
  }

  if (!supervisorCountTouched.value) {
    return null;
  }

  return backendSupervisorCountIssue.value ?? supervisorCountIssue.value;
});

const canShowFoodAndDrinkSelectionBase = computed(() => {
  return hasValidSupervisorCount.value;
});

const {
  canShowFoodAndDrinkSelection,
  hasValidFoodAndDrinkSelection,
  foodAndDrinkIssue,
  foodAndDrinkFlashTrigger,
  snackOptions,
  lunchOptions,
  formatCurrency,
  resetFoodAndDrinkSelection,
  normalizeFoodAndDrinkSelection,
} = useFoodAndDrinkSelection({
  formValues,
  bookingProgramConfig,
  canShowFoodAndDrinkSelectionBase,
});

const backendFoodAndDrinkIssue = ref<string | null>(null);
const foodAndDrinkTouched = ref(false);

const visibleFoodAndDrinkIssue = computed(() => {
  if (backendFoodAndDrinkIssue.value) {
    return backendFoodAndDrinkIssue.value;
  }

  if (!foodAndDrinkTouched.value) {
    return null;
  }

  return foodAndDrinkIssue.value;
});

const canShowPriceQuote = computed(() => {
  return (
    canShowFoodAndDrinkSelection.value &&
    hasValidFoodAndDrinkSelection.value &&
    bookingProgramConfig.value !== null
  );
});

const canShowTermsAcceptance = computed(() => {
  return canShowPriceQuote.value;
});

const canShowQuestionsAndComments = computed(() => {
  return canShowPriceQuote.value;
});

const termsFlashTrigger = ref(0);
const backendTermsIssue = ref<string | null>(null);
const termsTouched = ref(false);

const hasAcceptedTerms = computed(() => {
  return formValues.value.voorwaardenAkkoord;
});

const submitDisabledByTerms = computed(() => {
  return canShowTermsAcceptance.value && !formValues.value.voorwaardenAkkoord;
});

const termsIssue = computed(() => {
  if (hasAcceptedTerms.value) {
    return null;
  }

  return "Ga akkoord met de voorwaarden om de aanvraag te versturen.";
});

const visibleTermsIssue = computed(() => {
  if (backendTermsIssue.value) {
    return backendTermsIssue.value;
  }

  if (!termsTouched.value) {
    return null;
  }

  return termsIssue.value;
});





/**
 * Losse flash-trigger voor de modulekeuze.
 *
 * Dit veld zit niet in bookingFieldNames.
 */
const moduleFlashTrigger = ref(0);
const backendProgramIssue = ref<string | null>(null);
const backendModuleIssue = ref<string | null>(null);
const backendEducationSelectionIssue = ref<string | null>(null);

/**
 * Flash-triggers voor onderwijsniveau/groepen.
 *
 * Level heeft één trigger.
 * Groepen hebben een trigger per level.
 */

const visibleProgramSelectionIssue = computed(() => {
  return backendProgramIssue.value ?? programSelectionIssue.value;
});

const visibleModuleSelectionIssue = computed(() => {
  return backendModuleIssue.value ?? moduleSelectionIssue.value;
});



const educationLevelFlashTrigger = ref(0);
const educationGroupFlashTriggers = ref<Record<string, number>>({});

/**
 * Eén centrale check voor fouten in de onderwijsselectie.
 */
const hasEducationSelectionIssues = computed(() => {
  return (
    levelSelectionIssue.value !== null ||
    Object.keys(groupSelectionIssues.value).length > 0
  );
});

function flashEducationSelectionIssues(): void {
  if (levelSelectionIssue.value) {
    educationLevelFlashTrigger.value++;
  }

  for (const levelKey of Object.keys(groupSelectionIssues.value)) {
    educationGroupFlashTriggers.value[levelKey] =
      (educationGroupFlashTriggers.value[levelKey] ?? 0) + 1;
  }
}

function clearEducationFlashTriggers(): void {
  educationLevelFlashTrigger.value = 0;
  educationGroupFlashTriggers.value = {};
}

/* ==========================================================================
   Reset helpers
   ========================================================================== */

/**
 * Reset alleen het leerlingaantal.
 *
 * Dit gebruik je wanneer een bovenliggende keuze invloed kan hebben op
 * toegestane leerlingaantallen, maar de eerdere keuzes zelf mogen blijven staan.
 */
function resetStudentCount(): void {
  formValues.value.aantalLeerlingen = "";
  backendStudentCountIssue.value = null;
  studentCountFlashTrigger.value = 0;
  resetSupervisorCount();
}

function resetSupervisorCount(): void {
  formValues.value.aantalBegeleiders = "";
  backendSupervisorCountIssue.value = null;
  supervisorCountTouched.value = false;
  supervisorCountFlashTrigger.value = 0;
  backendFoodAndDrinkIssue.value = null;
  foodAndDrinkTouched.value = false;
  resetFoodAndDrinkSelection();
}

/**
 * Reset programma en alles daaronder.
 *
 * Gebruik bij:
 * - datumwijziging
 * - sectorwijziging
 */
function resetProgramAndDown(): void {
  formValues.value.programma = "";
  formValues.value.levelSelection = createEmptyLevelSelection();
  resetModuleAndDown();
}

/**
 * Reset onderwijsniveau/groepen en alles daaronder.
 *
 * Gebruik bij:
 * - programmawijziging
 */
function resetEducationSelectionAndDown(): void {
  formValues.value.levelSelection = createEmptyLevelSelection();
  resetModuleAndDown();
}
/**
 * Reset vanaf bezoekdatum naar beneden.
 *
 * Datum ongeldig betekent:
 * - sector weg
 * - programma weg
 * - onderwijsselectie weg
 * - leerlingaantal weg
 */
function resetFromVisitDateDown(): void {
  formValues.value.onderwijsSector = "";
  resetProgramAndDown();
}

/**
 * Reset vanaf sector naar beneden.
 *
 * Sectorwijziging betekent:
 * - programma weg
 * - onderwijsselectie weg
 * - leerlingaantal weg
 */
function resetFromSectorDown(): void {
  resetProgramAndDown();
}

/**
 * Reset vanaf programma naar beneden.
 *
 * Programma zelf blijft staan.
 * Alles daaronder wordt gewist.
 */
function resetFromProgramDown(): void {
  resetEducationSelectionAndDown();
}

/**
 * Reset modulekeuze en alles daaronder.
 *
 * Gebruik wanneer:
 * - programma wijzigt
 * - onderwijsniveau wijzigt
 * - groepen wijzigen
 */
function resetModuleAndDown(): void {
  formValues.value.keuzemodule = "";
  resetStudentCount();
}



/* ==========================================================================
   Field refs / focus helpers
   ========================================================================== */

function setFieldRef(
  field: BookingField,
  el: Element | ComponentPublicInstance | null,
): void {
  formFieldRefs.value[field] = el as InputFieldInstance | null;
}

function focusField(field: BookingField): void {
  formFieldRefs.value[field]?.focus();
}

/* ==========================================================================
   Normalization helpers
   ========================================================================== */

/**
 * Normaliseert één veld.
 *
 * Normalisatie is bewust los van validatie:
 * - normalisatie maakt input schoon
 * - validatie beoordeelt of de input geldig is
 */
function normalizeField(field: BookingField): void {
  if (field === "postcode") {
    if (!isCountryCode(formValues.value.land)) {
      return;
    }

    formValues.value.postcode = normalizePostcode(
      formValues.value.postcode,
      formValues.value.land,
    );

    return;
  }

  if (isPhoneBookingField(field)) {
    const phoneField = field as PhoneNumberField;

    formValues.value[phoneField] = normalizePhoneNumber(
      formValues.value[phoneField],
    );

    return;
  }

  if (field === "email") {
    formValues.value.email = normalizeEmail(formValues.value.email);
    return;
  }

  if (field === "hoeKentUGeoFort") {
    formValues.value.hoeKentUGeoFort = normalizeGeoFortDiscovery(
      formValues.value.hoeKentUGeoFort,
    );

    return;
  }

  if (field === "opmerkingen") {
    formValues.value.opmerkingen = normalizeQuestionsAndComments(
      formValues.value.opmerkingen,
    );

    return;
  }

  if (field === "cjpPasGebruik") {
    if (formValues.value.cjpPasGebruik !== "ja") {
      formValues.value.cjpPasGebruik = "nee";
    }

    return;
  }

  if (field === "cjpContactpersoonNaam") {
    formValues.value.cjpContactpersoonNaam = normalizeCjpPersonName(
      formValues.value.cjpContactpersoonNaam,
    );

    return;
  }

  if (field === "cjpPasnummer") {
    formValues.value.cjpPasnummer = normalizeCjpPasnumber(
      formValues.value.cjpPasnummer,
    );
  }
}

function returnPlaceholder(field: BookingField, country: string): string {
  const land = isCountryCode(country) ? country : "Nederland";

  return getPlaceHolder(field, land);
}



/* ==========================================================================
   Validation helpers
   ========================================================================== */

function singleFieldValidation(field: BookingField): void {
  normalizeField(field);

  formIssues.value[field] = validateField(
    field,
    formValues.value[field],
    formValues.value,
  );

  formFlashTriggers.value[field]++;
}

/**
 * Verwerkt veldfouten die terugkomen vanuit de backend.
 *
 * Let op:
 * Dit verwerkt nu alleen BookingField-fouten.
 * Programma/educationSelection/module-validatie kunnen later eigen handlers
 * krijgen als de backend die apart terugstuurt.
 */

type BackendValidationField =
  | BookingField
  | "programma"
  | "educationSelection"
  | "keuzemodule"
  | "aantalLeerlingen"
  | "aantalBegeleiders"
  | "foodAndDrink"
  | "remiseBreak"
  | "kazerneBreak"
  | "fortgrachtBreak"
  | "waterijsje"
  | "glasLimonade"
  | "lunchChoice"
  | "remiseLunch"
  | "voorwaardenAkkoord";

function handleValidationErrors(
  fieldErrors: Partial<Record<BackendValidationField, string>>,
): void {
  let firstKey: BookingField | null = null;

  /**
   * 1. Normale BookingField-errors.
   *
   * Deze kunnen via formIssues/formFlashTriggers lopen.
   */
  for (const field of bookingFieldNames) {
    const msg = fieldErrors[field];

    if (!msg) {
      continue;
    }

    formIssues.value[field] = { error: msg };
    formFlashTriggers.value[field]++;

    firstKey ??= field;
  }

  /**
   * 2. Programma-error vanuit backend.
   *
   * Bijvoorbeeld:
   * - programma bestaat niet
   * - programma past niet bij datum/sector
   */
  if (fieldErrors.programma) {
    backendProgramIssue.value = fieldErrors.programma;
    programFlashTrigger.value++;
  }

  /**
   * 3. Onderwijsselectie-error vanuit backend.
   *
   * Dit veld is een JSON payload en zit niet in bookingFieldNames.
   * We flashen de education selector en bewaren de backendmelding.
   */
  if (fieldErrors.educationSelection) {
    backendEducationSelectionIssue.value = fieldErrors.educationSelection;
    flashEducationSelectionIssues();
  }

  /**
   * 4. Keuzemodule-error vanuit backend.
   *
   * Bijvoorbeeld:
   * - ontbrekende module
   * - module niet beschikbaar voor sector/programma
   * - module niet geldig voor gekozen levels/groepen
   */
  if (fieldErrors.keuzemodule) {
    backendModuleIssue.value = fieldErrors.keuzemodule;
    moduleFlashTrigger.value++;
  }

  if (fieldErrors.aantalLeerlingen) {
    backendStudentCountIssue.value = fieldErrors.aantalLeerlingen;
    studentCountFlashTrigger.value++;
  }

  if (fieldErrors.aantalBegeleiders) {
    backendSupervisorCountIssue.value = fieldErrors.aantalBegeleiders;
    supervisorCountFlashTrigger.value++;
  }

  const foodAndDrinkError =
    fieldErrors.foodAndDrink ??
    fieldErrors.lunchChoice ??
    fieldErrors.remiseLunch ??
    fieldErrors.remiseBreak ??
    fieldErrors.kazerneBreak ??
    fieldErrors.fortgrachtBreak ??
    fieldErrors.waterijsje ??
    fieldErrors.glasLimonade;

  if (foodAndDrinkError) {
    backendFoodAndDrinkIssue.value = foodAndDrinkError;
    foodAndDrinkFlashTrigger.value++;
  }

  if (fieldErrors.voorwaardenAkkoord) {
    backendTermsIssue.value = fieldErrors.voorwaardenAkkoord;
    termsTouched.value = true;
    termsFlashTrigger.value++;
  }

  if (firstKey) {
    focusField(firstKey);
    return;
  }
}

/* ==========================================================================
   Field/change handlers
   ========================================================================== */

function handleFieldUpdate<K extends keyof BaseBookingFormValues>(
  field: K,
  value: BookingFormValues[K],
): void {
  formValues.value[field] = value;

  /**
   * Alleen bestaande meldingen live herberekenen.
   *
   * Daardoor verdwijnt een foutmelding direct wanneer de gebruiker corrigeert,
   * zonder dat elke toetsaanslag opnieuw een flash-animatie veroorzaakt.
   */
  if (formIssues.value[field]?.error || formIssues.value[field]?.warning) {
    formIssues.value[field] = validateField(
      field,
      value,
      formValues.value,
    );
  }
}

function handleCountryChange(): void {
  const fieldsToValidate: CountryDependentField[] = [
    "postcode",
    "schoolTelefoonnummer",
    "contactpersoonTelefoonnummer",
  ];

  for (const field of fieldsToValidate) {
    if (formValues.value[field].trim().length === 0) {
      continue;
    }

    normalizeField(field);

    formIssues.value[field] = validateField(
      field,
      formValues.value[field],
      formValues.value,
    );

    formFlashTriggers.value[field]++;
  }
}

function handleCjpUsageChange(value: string): void {
  formValues.value.cjpPasGebruik = value;

  formIssues.value.cjpPasGebruik = validateField(
    "cjpPasGebruik",
    value,
    formValues.value,
  );

  formFlashTriggers.value.cjpPasGebruik++;

  if (value === "ja") {
    return;
  }

  formValues.value.cjpContactpersoonNaam = "";
  formValues.value.cjpPasnummer = "";

  formIssues.value.cjpContactpersoonNaam = {};
  formIssues.value.cjpPasnummer = {};
}

function handleEducationSectorChange(value: SchoolSectorKey | ""): void {
  backendProgramIssue.value = null;
  backendEducationSelectionIssue.value = null;
  backendModuleIssue.value = null;

  formIssues.value.onderwijsSector = validateField(
    "onderwijsSector",
    value,
    formValues.value,
  );

  formFlashTriggers.value.onderwijsSector++;
}
/**
 * Programmakeuze handler.
 *
 * Belangrijk:
 * Deze functie reset niet zelf de onderwijsselectie.
 * Dat doet de watcher op formValues.programma.
 *
 * Daardoor staat resetgedrag op één centrale plek.
 */
function handleProgramChange(value: ProgramKey | ""): void {
  backendProgramIssue.value = null;
  backendEducationSelectionIssue.value = null;
  backendModuleIssue.value = null;

  setSelectedProgram(value);
  programFlashTrigger.value++;
}


function validateProgramSelection(): void {
  programFlashTrigger.value++;
}

function handleModuleChange(value: EducationModuleKey | ""): void {
  backendModuleIssue.value = null;

  setSelectedModule(value);
  moduleFlashTrigger.value++;
}

function validateModuleSelection(): void {
  moduleFlashTrigger.value++;
}

function handleStudentCountChange(value: string): void {
  backendStudentCountIssue.value = null;
  formValues.value.aantalLeerlingen = value;
}

function validateStudentCount(): void {
  studentCountFlashTrigger.value++;
}

function handleSupervisorCountChange(value: string): void {
  backendSupervisorCountIssue.value = null;
  supervisorCountTouched.value = true;
  formValues.value.aantalBegeleiders = value;
}

function validateSupervisorCount(): void {
  supervisorCountTouched.value = true;
  supervisorCountFlashTrigger.value++;
}

function handleFoodAndDrinkChange(): void {
  backendFoodAndDrinkIssue.value = null;
}

function validateFoodAndDrinkSelection(): void {
  foodAndDrinkTouched.value = true;
  foodAndDrinkFlashTrigger.value++;
}

function handleTermsChange(): void {
  backendTermsIssue.value = null;
  termsTouched.value = true;
}

function validateTermsAcceptance(): void {
  termsTouched.value = true;
  termsFlashTrigger.value++;
}

/* ==========================================================================
   Submit helpers
   ========================================================================== */

function getCurrentEducationSelectionPayload() {
  const sector = currentSchoolSector.value;

  if (!sector) {
    return null;
  }

  return {
    sector,
    selectedLevels: formValues.value.levelSelection.selectedLevels[sector],
    selectedGroupsByLevel:
      formValues.value.levelSelection.selectedGroupsByLevel[sector],
  };
}

const {
  roster,
  isLoading: isRosterLoading,
  errorMessage: rosterErrorMessage,
} = useBookingRoster({
  formValues,
  canLoadRoster: hasValidSupervisorCount,
  getEducationSelectionPayload: getCurrentEducationSelectionPayload,
});

const {
  priceQuote,
  isLoading: isPriceQuoteLoading,
  errorMessage: priceQuoteErrorMessage,
  formatCurrency: formatPriceQuoteCurrency,
} = useBookingPriceQuote({
  formValues,
  canLoadPriceQuote: canShowPriceQuote,
});

/* ==========================================================================
   Submit
   ========================================================================== */

async function onSubmit(): Promise<void> {

  backendProgramIssue.value = null;
  backendEducationSelectionIssue.value = null;
  backendModuleIssue.value = null;
  backendStudentCountIssue.value = null;
  backendSupervisorCountIssue.value = null;
  backendFoodAndDrinkIssue.value = null;
  backendTermsIssue.value = null;

  /**
   * Normaliseer alle standaardvelden vóór validatie en verzending.
   */
  for (const field of bookingFieldNames) {
    normalizeField(field);
  }

  const shouldSendCjpDetails = formValues.value.cjpPasGebruik === "ja";

  if (!shouldSendCjpDetails) {
    formValues.value.cjpContactpersoonNaam = "";
    formValues.value.cjpPasnummer = "";
    formIssues.value.cjpContactpersoonNaam = {};
    formIssues.value.cjpPasnummer = {};
  }

  const {
    issues: validationErrors,
    firstError,
  } = validateAll(formValues.value);

  formIssues.value = validationErrors;

  /**
   * Flash voor alle standaardvelden.
   * De child-component toont alleen iets wanneer er echt een issue is.
   */
  for (const field of bookingFieldNames) {
    formFlashTriggers.value[field]++;
  }

  const programHasErrors =
    canShowProgramSelect.value && programSelectionIssue.value !== null;

  const educationHasErrors =
    canShowEducationDetails.value && hasEducationSelectionIssues.value;

  const moduleHasErrors =
    canShowModuleSelect.value && moduleSelectionIssue.value !== null;

  const studentCountHasErrors =
  canShowStudentCount.value && visibleStudentCountIssue.value !== null;

  if (canShowSupervisorCount.value && supervisorCountIssue.value !== null) {
    supervisorCountTouched.value = true;
  }

  const supervisorCountHasErrors =
    canShowSupervisorCount.value && visibleSupervisorCountIssue.value !== null;

  const foodAndDrinkHasErrors =
    canShowFoodAndDrinkSelection.value && foodAndDrinkIssue.value !== null;

  const termsHasErrors =
    canShowTermsAcceptance.value && !hasAcceptedTerms.value;

  if (programHasErrors) {
    programFlashTrigger.value++;
  }

  if (educationHasErrors) {
    flashEducationSelectionIssues();
  }

  if (moduleHasErrors) {
    moduleFlashTrigger.value++;
  }

  if (studentCountHasErrors) {
    studentCountFlashTrigger.value++;
  }

  if (supervisorCountHasErrors) {
    supervisorCountFlashTrigger.value++;
  }

  if (foodAndDrinkHasErrors) {
    foodAndDrinkTouched.value = true;
    foodAndDrinkFlashTrigger.value++;
  }

  if (termsHasErrors) {
    termsTouched.value = true;
    termsFlashTrigger.value++;
  }

  /**
   * Eerst standaardvelden afhandelen.
   * Daarna pas custom flow-validatie zoals programma en onderwijsselectie.
   */
  if (firstError) {
    focusField(firstError);
    return;
  }

  if (programHasErrors) {
    return;
  }

  if (educationHasErrors) {
    return;
  }

  if (moduleHasErrors) {
    return;
  }

  if (studentCountHasErrors) {
    return;
  }

  if (supervisorCountHasErrors) {
    return;
  }

  if (foodAndDrinkHasErrors) {
    return;
  }

  if (canShowFoodAndDrinkSelection.value && !hasValidFoodAndDrinkSelection.value) {
    foodAndDrinkFlashTrigger.value++;
    return;
  }

  if (termsHasErrors) {
    return;
  }

  normalizeFoodAndDrinkSelection();

  const formData = new FormData();

  const fieldsToSend = shouldSendCjpDetails
    ? bookingFieldNames
    : bookingFieldNames.filter(
        (field) => !["cjpContactpersoonNaam", "cjpPasnummer"].includes(field),
      );

  for (const field of fieldsToSend) {
    formData.append(field, formValues.value[field]);
  }

  /**
   * Programma zit niet in bookingFieldNames.
   * Daarom sturen we deze expliciet mee.
   */
  if (formValues.value.programma !== "") {
    formData.append("programma", formValues.value.programma);
  }

  const educationSelectionPayload = getCurrentEducationSelectionPayload();

  if (educationSelectionPayload) {
    formData.append(
      "educationSelection",
      JSON.stringify(educationSelectionPayload),
    );
  }

  formData.append("keuzemodule", formValues.value.keuzemodule);
  formData.append("aantalLeerlingen", formValues.value.aantalLeerlingen);
  formData.append("aantalBegeleiders", formValues.value.aantalBegeleiders);
  formData.append("remiseBreak", formValues.value.remiseBreak);
  formData.append("kazerneBreak", formValues.value.kazerneBreak);
  formData.append("fortgrachtBreak", formValues.value.fortgrachtBreak);
  formData.append("waterijsje", formValues.value.waterijsje);
  formData.append("glasLimonade", formValues.value.glasLimonade);
  formData.append("lunchChoice", formValues.value.lunchChoice);
  formData.append("remiseLunch", formValues.value.remiseLunch);
  formData.append(
    "voorwaardenAkkoord",
    formValues.value.voorwaardenAkkoord ? "1" : "0",
  );

  const result = (await submit(formData)) as ApiResponse;

  if (result.ok) {
    emit("success");
    return;
  }

  switch (result.type) {
    case "validation":
      handleValidationErrors(result.fieldErrors);
      return;

    case "rate-limit":
      /**
       * Rate-limit feedback wordt afgehandeld door useFormSubmit().
       */
      return;

    case "server":
      /**
       * Alleen een kritisch server-event emitten wanneer de composable ook echt
       * in error-state staat. Zo voorkom je dubbele meldingen bij netwerkgedrag.
       */
      if (state.value === "error") {
        emit("server-error", {
          message: "Het online formulier liep tegen een kritieke fout aan",
        });
      }

      return;
  }
}

/* ==========================================================================
   Watchers: reset flow
   ========================================================================== */

/**
 * Datumwijziging.
 *
 * Datum beïnvloedt:
 * - beschikbare sectoren indirect via validiteit
 * - beschikbare programma’s
 * - alles onder programma
 */
watch(
  () => formValues.value.bezoekdatum,
  () => {
    if (!hasValidVisitDate.value) {
      resetFromVisitDateDown();
      clearEducationFlashTriggers();
      programFlashTrigger.value = 0;
      return;
    }

    resetProgramAndDown();
    clearEducationFlashTriggers();
    programFlashTrigger.value = 0;
  },
);

/**
 * Sectorwijziging.
 *
 * Sector beïnvloedt:
 * - beschikbare programma’s
 * - beschikbare levels
 * - beschikbare groepen
 * - latere modulekeuze
 */
watch(
  () => formValues.value.onderwijsSector,
  (newSector, oldSector) => {
    if (newSector === oldSector) {
      return;
    }

    resetFromSectorDown();
    clearEducationFlashTriggers();
    programFlashTrigger.value = 0;
  },
);

/**
 * Programmawijziging.
 *
 * Programma beïnvloedt:
 * - module-aanbod
 * - later mogelijk leerlinglimieten
 *
 * Daarom resetten we alles onder programma.
 */
watch(
  () => formValues.value.programma,
  () => {
    resetFromProgramDown();
    clearEducationFlashTriggers();
    moduleFlashTrigger.value = 0;
  },
);
/**
 * Defensieve programma-reset.
 *
 * Voorbeeld:
 * - gebruiker kiest PO + woensdag + ochtend
 * - datum verandert naar donderdag
 * - ochtend is mogelijk niet meer beschikbaar
 */
watch(selectedProgramIsStillAvailable, (isStillAvailable) => {
  if (!isStillAvailable) {
    resetSelectedProgram();
    resetFromProgramDown();
    clearEducationFlashTriggers();
    programFlashTrigger.value = 0;
  }
});

/**
 * Levelwijziging.
 *
 * Wanneer levels wijzigen:
 * - groepen van verwijderde levels opschonen
 * - leerlingaantal resetten
 *
 * Later:
 * - modulekeuze ook resetten
 */
watch(
  () => selectedLevelsForCurrentSector.value.join("|"),
  () => {
    backendEducationSelectionIssue.value = null;
    backendModuleIssue.value = null;

    pruneGroupsForDeselectedLevels();
    resetModuleAndDown();
    moduleFlashTrigger.value = 0;

    if (!levelSelectionIssue.value) {
      educationLevelFlashTrigger.value = 0;
    }

    for (const levelKey of Object.keys(educationGroupFlashTriggers.value)) {
      if (!groupSelectionIssues.value[levelKey]) {
        delete educationGroupFlashTriggers.value[levelKey];
      }
    }
  },
);
/**
 * Groepwijziging.
 *
 * Wanneer groepen wijzigen:
 * - leerlingaantal resetten
 *
 * Later:
 * - modulekeuze ook resetten
 */
watch(
  () => JSON.stringify(selectedGroupsForCurrentSector.value),
  () => {
    backendEducationSelectionIssue.value = null;
    backendModuleIssue.value = null;

    resetModuleAndDown();
    moduleFlashTrigger.value = 0;

    for (const levelKey of Object.keys(educationGroupFlashTriggers.value)) {
      if (!groupSelectionIssues.value[levelKey]) {
        delete educationGroupFlashTriggers.value[levelKey];
      }
    }
  },
);

/**
 * Defensieve module-reset.
 *
 * Voorbeeld:
 * - gebruiker kiest havo + havo1
 * - kiest een module
 * - wijzigt daarna naar vwo
 * - oude module is mogelijk niet meer geldig
 */
watch(selectedModuleIsStillAvailable, (isStillAvailable) => {
  if (!isStillAvailable) {
    resetSelectedModule();
    moduleFlashTrigger.value = 0;
  }
});

watch(
  () => formValues.value.aantalLeerlingen,
  (newValue, oldValue) => {
    if (newValue === oldValue) {
      return;
    }

    resetSupervisorCount();
  },
);

watch(canShowFoodAndDrinkSelection, (canShow) => {
  if (!canShow) {
    backendFoodAndDrinkIssue.value = null;
    foodAndDrinkTouched.value = false;
    resetFoodAndDrinkSelection();
  }
});

watch(canShowTermsAcceptance, (canShow) => {
  if (!canShow) {
    formValues.value.voorwaardenAkkoord = false;
    backendTermsIssue.value = null;
    termsTouched.value = false;
    termsFlashTrigger.value = 0;
  }
});
</script>
<template>
  <div class="app-bg">
    <div class="app-shell">
      <main
        class="main transform"
        ref="scrollContainer"
        :class="{ 'page--visible': pageVisible }"
      >
        <h1 class="main-title main-title--with-icon">
          <span
            class="main-title__icon"
            aria-hidden="true"
          >
            <GraduationCap
              :size="24"
              :stroke-width="2.5"
            />
          </span>

          Onderwijs Aanvraagformulier
        </h1>

        <form
          action=""
          class="form-geoform"
          @submit.prevent="onSubmit"
        >
          <!-- ============================================================
            BASISGEGEVENS
          ============================================================= -->

          <fieldset class="fieldset-geoform">
            <legend class="legend-geoform">BASISGEGEVENS</legend>

            <template
              v-for="field in basisFieldNames"
              :key="field"
            >
              <!-- Land -->
              <GeoFormSelectField
                v-if="field === 'land'"
                :id="BookingFieldConfig[field].id"
                :label="BookingFieldConfig[field].label"
                :options="countryOptions"
                :required="BookingFieldConfig[field].required"
                :autocomplete="BookingFieldConfig[field].autocomplete"
                :issue="formIssues[field]"
                :flash-trigger="formFlashTriggers[field]"
                v-model="formValues[field]"
                :ref="(el) => setFieldRef(field, el)"
                @change="handleCountryChange"
                @blur="singleFieldValidation(field)"
              />

              <!-- Bezoekdatum -->
              <GeoBookingDateField
                v-else-if="field === 'bezoekdatum'"
                :id="BookingFieldConfig[field].id"
                :label="BookingFieldConfig[field].label"
                :required="BookingFieldConfig[field].required"
                :issue="formIssues[field]"
                :flash-trigger="formFlashTriggers[field]"
                v-model="formValues[field]"
                :ref="(el) => setFieldRef(field, el)"
                @blur="singleFieldValidation(field)"
                @availability-loaded="handleAgendaDataLoaded"
              />

              <!-- Hoe kent u GeoFort -->
              <GeoDiscoverySelectField
                v-else-if="field === 'hoeKentUGeoFort'"
                :id="BookingFieldConfig[field].id"
                :label="BookingFieldConfig[field].label"
                :required="BookingFieldConfig[field].required"
                :issue="formIssues[field]"
                :flash-trigger="formFlashTriggers[field]"
                v-model="formValues[field]"
                :options="geofortDiscoverySelectOptions"
                :other-option="otherOption"
                :ref="(el) => setFieldRef(field, el)"
                @blur="singleFieldValidation(field)"
              />

              <!-- CJP-korting -->
              <template v-else-if="field === 'cjpPasGebruik'">
                <GeoFormRadioGroup
                  :id="BookingFieldConfig.cjpPasGebruik.id"
                  :label="BookingFieldConfig.cjpPasGebruik.label"
                  :required="BookingFieldConfig.cjpPasGebruik.required"
                  :options="cjpUsageOptions"
                  :issue="formIssues.cjpPasGebruik"
                  :flash-trigger="formFlashTriggers.cjpPasGebruik"
                  v-model="formValues.cjpPasGebruik"
                  :inputMode="BookingFieldConfig.cjpPasGebruik.inputmode"
                  :ref="(el) => setFieldRef('cjpPasGebruik', el)"
                  @change="handleCjpUsageChange"
                  @blur="singleFieldValidation('cjpPasGebruik')"
                />

                <Transition name="cjp-reveal">
                  <div
                    v-show="usesCjpDiscount"
                    class="cjp-extra-fields"
                  >
                    <GeoFormInputField
                      :id="BookingFieldConfig.cjpContactpersoonNaam.id"
                      :label="BookingFieldConfig.cjpContactpersoonNaam.label"
                      :type="BookingFieldConfig.cjpContactpersoonNaam.type"
                      :required="usesCjpDiscount"
                      :autocomplete="BookingFieldConfig.cjpContactpersoonNaam.autocomplete"
                      :inputmode="BookingFieldConfig.cjpContactpersoonNaam.inputmode"
                      :placeholder="BookingFieldConfig.cjpContactpersoonNaam.placeholder"
                      :disabled="!usesCjpDiscount"
                      :issue="formIssues.cjpContactpersoonNaam"
                      :flash-trigger="formFlashTriggers.cjpContactpersoonNaam"
                      v-model="formValues.cjpContactpersoonNaam"
                      :ref="(el) => setFieldRef('cjpContactpersoonNaam', el)"
                      @update:model-value="
                        (value) => handleFieldUpdate('cjpContactpersoonNaam', value)
                      "
                      @blur="singleFieldValidation('cjpContactpersoonNaam')"
                    />

                    <GeoFormInputField
                      :id="BookingFieldConfig.cjpPasnummer.id"
                      :label="BookingFieldConfig.cjpPasnummer.label"
                      :type="BookingFieldConfig.cjpPasnummer.type"
                      :required="usesCjpDiscount"
                      :autocomplete="BookingFieldConfig.cjpPasnummer.autocomplete"
                      :inputmode="BookingFieldConfig.cjpPasnummer.inputmode"
                      :placeholder="BookingFieldConfig.cjpPasnummer.placeholder"
                      :disabled="!usesCjpDiscount"
                      :issue="formIssues.cjpPasnummer"
                      :flash-trigger="formFlashTriggers.cjpPasnummer"
                      v-model="formValues.cjpPasnummer"
                      :ref="(el) => setFieldRef('cjpPasnummer', el)"
                      @update:model-value="
                        (value) => handleFieldUpdate('cjpPasnummer', value)
                      "
                      @blur="singleFieldValidation('cjpPasnummer')"
                    />
                  </div>
                </Transition>
              </template>

              <!--
                CJP-subvelden worden hierboven conditioneel gerenderd.
                Daarom renderen we ze hier niet nog een keer als standaard input.
              -->
              <template
                v-else-if="
                  field === 'cjpContactpersoonNaam' ||
                  field === 'cjpPasnummer'
                "
              />

              <!-- Standaard tekstvelden -->
              <GeoFormInputField
                v-else
                :id="BookingFieldConfig[field].id"
                :label="BookingFieldConfig[field].label"
                :type="BookingFieldConfig[field].type"
                :required="BookingFieldConfig[field].required"
                :autocomplete="BookingFieldConfig[field].autocomplete"
                :inputmode="BookingFieldConfig[field]?.inputmode"
                :placeholder="returnPlaceholder(field, formValues.land)"
                :issue="formIssues[field]"
                :flash-trigger="formFlashTriggers[field]"
                v-model="formValues[field]"
                :ref="(el) => setFieldRef(field, el)"
                @update:model-value="(value) => handleFieldUpdate(field, value)"
                @blur="singleFieldValidation(field)"
              />

              <!-- Telefoonnummer toelichting -->
              <GeoInfoToggle
                v-if="field === 'contactpersoonTelefoonnummer'"
                id="telefoonInfo"
                label="Meer informatie over de telefoonnummers"
                open-label="Verberg informatie over telefoonnummers"
              >
                <p>
                  <strong>Telefoonnummer van de school:</strong>
                  Het nummer waarop GeoFort de school kan bereiken. Gebruik een
                  vast of mobiel nummer.
                </p>

                <p>
                  <strong>Telefoonnummer contactpersoon:</strong>
                  GeoFort verwacht een mobiel nummer om de contactpersoon te
                  kunnen bereiken.
                </p>
              </GeoInfoToggle>
            </template>
          </fieldset>

          <!-- ============================================================
            PRAKTISCHE INFORMATIE
          ============================================================= -->

          <fieldset
            v-if="bookingProgramConfig"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              PRAKTISCHE INFORMATIE
            </legend>

            <BookingInfoCard :config="bookingProgramConfig" />
          </fieldset>

          <!-- ============================================================
            LESPROGRAMMA
          ============================================================= -->

          <fieldset class="fieldset-geoform">
            <legend class="legend-geoform">LESPROGRAMMA</legend>

            <GeoFormEducationTypeSelect
              v-if="bookingProgramConfig"
              :id="BookingFieldConfig.onderwijsSector.id"
              :label="BookingFieldConfig.onderwijsSector.label"
              :options="selectorOptions"
              :programs="bookingProgramConfig.programs"
              :required="BookingFieldConfig.onderwijsSector.required"
              :visit-date="formValues.bezoekdatum"
              :issue="formIssues.onderwijsSector"
              :flash-trigger="formFlashTriggers.onderwijsSector"
              v-model="formValues.onderwijsSector"
              :ref="(el) => setFieldRef('onderwijsSector', el)"
              @blur="singleFieldValidation('onderwijsSector')"
              @change="handleEducationSectorChange"
            />

            <Transition name="program-reveal">
              <GeoFormProgramSelect
                v-if="canShowProgramSelect"
                id="programma"
                label="Kies het programma"
                :required="true"
                :options="availableProgramOptions"
                :issue="programSelectionIssue"
                :flash-trigger="programFlashTrigger"
                v-model="formValues.programma"
                @change="handleProgramChange"
                @blur="validateProgramSelection"
              />
            </Transition>

            <Transition name="education-level-reveal">
              <GeoFormEducationLevelSelector
                v-if="canShowEducationDetails"
                :levels="availableLevelsForSector"
                v-model:selected-levels="selectedLevelsForCurrentSector"
                v-model:selected-groups-by-level="selectedGroupsForCurrentSector"
                :rules="currentLevelSelectionRules"
                :sector="currentSchoolSector"
                :level-issue="levelSelectionIssue"
                :group-issues="groupSelectionIssues"
                :level-flash-trigger="educationLevelFlashTrigger"
                :group-flash-triggers="educationGroupFlashTriggers"
              />
            </Transition>

            <Transition name="education-module-reveal">
              <GeoFormEducationModuleSelector
                v-if="canShowModuleSelect"
                id="keuzemodule"
                label="Kies de keuzemodule"
                :required="requiresChoiceModule"
                :standard-modules="standardModuleOptions"
                :choice-modules="choiceModuleOptions"
                :issue="visibleModuleSelectionIssue"
                :flash-trigger="moduleFlashTrigger"
                v-model="formValues.keuzemodule"
                @change="handleModuleChange"
                @blur="validateModuleSelection"
              />
            </Transition>

            <Transition name="student-count-reveal">
              <GeoFormStudentCountField
                  v-if="canShowStudentCount"
                  id="aantalLeerlingen"
                  label="Aantal leerlingen"
                  :required="true"
                  :min-students="minStudents"
                  :max-students="effectiveMaxStudents"
                  :placeholder="studentCountPlaceholder"
                  :available-students="availableStudentsForDate"
                  :limit-text="studentCountLimitText"
                  :help-text="studentCountHelpText"
                  :issue="visibleStudentCountIssue"
                  :flash-trigger="studentCountFlashTrigger"
                  v-model="formValues.aantalLeerlingen"
                  @change="handleStudentCountChange"
                  @blur="validateStudentCount"
                />
              </Transition>

            <Transition name="student-count-reveal">
              <GeoFormSupervisorCountField
                v-if="canShowSupervisorCount"
                id="aantalBegeleiders"
                label="Aantal begeleiders"
                :required="true"
                :max-supervisors="maxSupervisors"
                :free-supervisors="freeSupervisorCount"
                :placeholder="supervisorCountPlaceholder"
                :limit-text="supervisorCountLimitText"
                :help-text="supervisorCountHelpText"
                :issue="visibleSupervisorCountIssue"
                :flash-trigger="supervisorCountFlashTrigger"
                v-model="formValues.aantalBegeleiders"
                @change="handleSupervisorCountChange"
                @blur="validateSupervisorCount"
              />
            </Transition>

            <Transition name="student-count-reveal">
              <GeoFormRosterPreview
                v-if="hasValidSupervisorCount"
                id="conceptrooster"
                label="Conceptrooster"
                :roster="roster"
                :is-loading="isRosterLoading"
                :error-message="rosterErrorMessage"
              />
            </Transition>

          </fieldset>

          <!-- ============================================================
            ETEN EN DRINKEN INFORMATIE
          ============================================================= -->

          <fieldset
            v-if="canShowFoodAndDrinkSelection && bookingProgramConfig"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              ETEN EN DRINKEN
            </legend>

            <GeoFormFoodAndDrinkInfoPanel
              id="foodAndDrinkInfo"
              label="Eten en drinken"
              :info="bookingProgramConfig.foodAndDrinkInfo"
              :prices="bookingProgramConfig.prices"
            />
          </fieldset>

          <fieldset
            v-if="canShowFoodAndDrinkSelection && bookingProgramConfig"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              ETEN EN DRINKEN KEUZEMENU
            </legend>

            <GeoFormFoodAndDrinkSelectionField
              id="foodAndDrinkSelection"
              label="Eten en drinken"
              :required="true"
              :snack-options="snackOptions"
              :lunch-options="lunchOptions"
              :issue="visibleFoodAndDrinkIssue"
              :flash-trigger="foodAndDrinkFlashTrigger"
              :format-currency="formatCurrency"
              v-model:remise-break="formValues.remiseBreak"
              v-model:kazerne-break="formValues.kazerneBreak"
              v-model:fortgracht-break="formValues.fortgrachtBreak"
              v-model:waterijsje="formValues.waterijsje"
              v-model:glas-limonade="formValues.glasLimonade"
              v-model:lunch-choice="formValues.lunchChoice"
              v-model:remise-lunch="formValues.remiseLunch"
              @change="handleFoodAndDrinkChange"
              @blur="validateFoodAndDrinkSelection"
            />
          </fieldset>

          <fieldset
            v-if="canShowPriceQuote"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              PRIJSOPGAVE
            </legend>

            <GeoFormPriceQuotePreview
              id="priceQuote"
              label="Prijsopgave"
              :quote="priceQuote"
              :is-loading="isPriceQuoteLoading"
              :error-message="priceQuoteErrorMessage"
              :format-currency="formatPriceQuoteCurrency"
            />
          </fieldset>

          <fieldset
            v-if="canShowQuestionsAndComments"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              VRAGEN EN OPMERKINGEN
            </legend>

            <GeoFormTextareaField
              id="opmerkingen"
              label="Vragen en opmerkingen"
              placeholder="Heeft u nog vragen, bijzonderheden of aanvullende wensen?"
              :required="false"
              :maxlength="600"
              :rows="5"
              :issue="formIssues.opmerkingen"
              :flash-trigger="formFlashTriggers.opmerkingen"
              v-model="formValues.opmerkingen"
              :ref="(el) => setFieldRef('opmerkingen', el)"
              @update:model-value="(value) => handleFieldUpdate('opmerkingen', value)"
              @blur="singleFieldValidation('opmerkingen')"
            />

            <p class="questions-comments-contact">
              Voor aanvullende vragen:<br>
              <a href="mailto:onderwijs@geofort.nl">onderwijs@geofort.nl</a>
            </p>
          </fieldset>

          <fieldset
            v-if="canShowTermsAcceptance"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              VOORWAARDEN EN AFRONDING
            </legend>

            <GeoFormTermsAcceptanceField
              id="voorwaardenAkkoord"
              label="Algemene voorwaarden"
              :required="true"
              :terms-url="termsUrl"
              :issue="visibleTermsIssue"
              :flash-trigger="termsFlashTrigger"
              v-model="formValues.voorwaardenAkkoord"
              @change="handleTermsChange"
              @blur="validateTermsAcceptance"
            />
          </fieldset>

          <!-- ============================================================
            FORMULIERFOUTEN EN SUBMIT
          ============================================================= -->

          <FormError
            :message="formError"
            @dismiss="clearFormError"
          />

          <GeoBtn
            :state="state"
            :disabled="submitDisabledByTerms"
            disabled-reason="Vink voorwaarden aan om te verzenden."
          />
        </form>
      </main>

      <GeoFooter />
    </div>
  </div>
</template>
