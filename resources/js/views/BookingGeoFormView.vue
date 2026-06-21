<script setup lang="ts">
/**
 * Booking form parent
 *
 * Deze component houdt de formulierflow bij elkaar:
 * - configuratie ophalen
 * - formulier-state beheren
 * - velden normaliseren en valideren
 * - formulier verzenden
 * - resetgedrag tussen datum, sector, levels, groepen en programma
 *
 * De onderwijsniveau/groep-logica zelf zit bewust in useEducationSelection().
 */

import { GraduationCap } from "lucide-vue-next";
import {
  computed,
  onMounted,
  ref,
  watch,
  type ComponentPublicInstance,
} from "vue";

import GeoBookingDateField from "./../components/form/GeoFormBookingDateField.vue";
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue";
import GeoDiscoverySelectField from "./../components/form/GeoFormDiscoveryField.vue";
import GeoFooter from "../components/layout/AppFooter.vue";
import GeoFormEducationTypeSelect from "../components/form/GeoFormEducationSectorSelect.vue";
import GeoFormInputField from "./../components/form/GeoFormInputField.vue";
import GeoFormRadioGroup from "../components/form/GeoFormRadioGroup.vue";
import GeoFormSelectField from "../components/form/GeoFormSelectFied.vue";
import GeoInfoToggle from "../components/form/GeoInfoToggles.vue";
import BookingInfoCard from "../components/form/GeoFormBookingProgramInfoPanel.vue";
import GeoFormEducationLevelSelector from "./../components/form/GeoFormEducationLevelSelector.vue"
import FormError from "./../components/form/FormLevelError.vue";

import { useEducationSelection } from "../composables/useEducationSelection";
import { useFormSubmit } from "../composables/useFormSubmit.ts";
import { useScrollIndicator } from "../composables/useScrollindicator.ts";

import { fetchBookingProgramConfigValues } from "../services/api/bookingProgramConfigApi.ts";

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
  programFieldNames,
} from "./../config/booking/BookingFieldConstants.ts";

import {
  createInitialFieldRefs,
  createInitialFlashTriggers,
  createInitialIssues,
} from "./../config/booking/BookingFormState.ts";

import { getIsValidWeekDayFromDate } from "../config/booking/calendar/helpers.ts";

import {
  geofortDiscoverySelectOptions,
  isCountryCode,
  normalizeCjpPasnumber,
  normalizeCjpPersonName,
  normalizeEmail,
  normalizeGeoFortDiscovery,
  normalizePhoneNumber,
  normalizePostcode,
  otherOption,
  validateAll,
  validateField,
} from "./../config/validation/booking.ts";

import { isValidSchoolSector } from "../config/validation/helpers.ts";

import type {
  CountryDependentField,
  InputFieldInstance,
  PhoneNumberField,
  SchoolSectorOption,
  BookingField,
} from "../types/booking/BookingFieldTypes.ts";

import type {
  BookingProgramConfigData,
  SchoolSectorKey,
} from "../types/booking/BookingProgramConfigTypes.ts";

import type { ApiResponse } from "../types/http/ApiResponse.ts";
import { getLevelSelectionIssue } from "../config/booking/educationSelectionHelpers.ts";

/**
 * Scroll-indicator wordt buiten de formulierflow gehouden.
 */
useScrollIndicator(window);

/**
 * Component events
 */
const emit = defineEmits<{
  success: [];
  "server-error": [{ message: string }];
}>();

/**
 * Page/API state
 */
const pageVisible = ref(false);
const bookingProgramConfig = ref<BookingProgramConfigData | null>(null);

onMounted(async () => {
  bookingProgramConfig.value = await fetchBookingProgramConfigValues();

  /**
   * Eerst de startwaarde renderen, daarna pas de page-visible class toevoegen.
   * Daardoor blijft de enter-animatie betrouwbaar.
   */
  requestAnimationFrame(() => {
    pageVisible.value = true;
  });
});

/**
 * Form state
 */
const formValues = ref<BookingFormValues>(createInitialBookingForm());
const formIssues = ref(createInitialIssues());
const formFlashTriggers = ref(createInitialFlashTriggers());
const formFieldRefs = ref(createInitialFieldRefs());

/**
 * Submit state
 */
const {
  state,
  formError,
  submit,
  clearFormError,
} = useFormSubmit();

/**
 * Basic derived form state
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

const hasValidVisitDate = computed(() => {
  return getIsValidWeekDayFromDate(formValues.value.bezoekdatum);
});

const hasValidSchoolSector = computed(() => {
  return isValidSchoolSector(formValues.value.onderwijsSector);
});

const currentSchoolSector = computed<SchoolSectorKey | null>(() => {
  return hasValidSchoolSector.value
    ? (formValues.value.onderwijsSector as SchoolSectorKey)
    : null;
});

const canShowEducationDetails = computed(() => {
  return hasValidVisitDate.value && currentSchoolSector.value !== null;
});

const usesCjpDiscount = computed(() => {
  return formValues.value.cjpPasGebruik === "ja";
});

/**
 * Education level/group state
 *
 * De echte level/groep-logica zit in de composable.
 * De parent gebruikt alleen de uitkomsten om de formulierflow te sturen.
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

/**
 * refs for flashtrigger levels and groups
 */
const educationLevelFlashTrigger = ref(0);
const educationGroupFlashTriggers = ref<Record<string, number>>({});

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
/**
 * Visibility state
 */
const canShowProgramSelect = computed(() => {
  return (
    hasValidVisitDate.value &&
    hasValidSchoolSector.value &&
    hasValidLevelSelection.value &&
    hasValidGroupSelection.value
  );
});

const canShowStudentCount = computed(() => {
  return canShowProgramSelect.value && formValues.value.programma !== "";
});

/**
 * Reset helpers
 */
function resetProgramAndStudentCount(): void {
  formValues.value.programma = "";
  formValues.value.aantalLeerlingen = "";
}

function resetLevelsGroupsProgramAndStudentCount(): void {
  formValues.value.levelSelection = createEmptyLevelSelection();
  resetProgramAndStudentCount();
}

function resetFromVisitDateDown(): void {
  formValues.value.onderwijsSector = "";
  resetLevelsGroupsProgramAndStudentCount();
}

/**
 * Field refs/focus helpers
 */
function setFieldRef(
  field: BookingField,
  el: Element | ComponentPublicInstance | null,
): void {
  formFieldRefs.value[field] = el as InputFieldInstance | null;
}

function focusField(field: BookingField): void {
  formFieldRefs.value[field]?.focus();
}

/**
 * Normalization helpers
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

/**
 * Validation helpers
 */
function singleFieldValidation(field: BookingField): void {
  normalizeField(field);

  formIssues.value[field] = validateField(
    field,
    formValues.value[field],
    formValues.value,
  );

  formFlashTriggers.value[field]++;
}

function handleValidationErrors(
  fieldErrors: Partial<Record<BookingField, string>>,
): void {
  let firstKey: BookingField | null = null;

  for (const field of bookingFieldNames) {
    const msg = fieldErrors[field];

    if (!msg) {
      continue;
    }

    formIssues.value[field] = { error: msg };
    formFlashTriggers.value[field]++;

    firstKey ??= field;
  }

  if (firstKey) {
    focusField(firstKey);
  }
}

/**
 * Field/change handlers
 */
function handleFieldUpdate<K extends keyof BaseBookingFormValues>(
  field: K,
  value: BookingFormValues[K],
): void {
  formValues.value[field] = value;

  /**
   * Alleen bestaande meldingen live herberekenen.
   * Zo verdwijnt een foutmelding direct wanneer de gebruiker het veld corrigeert,
   * zonder dat elk toetsaanslagje opnieuw een flash-animatie veroorzaakt.
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
  formIssues.value.onderwijsSector = validateField(
    "onderwijsSector",
    value,
    formValues.value,
  );

  formFlashTriggers.value.onderwijsSector++;
}

/**
 * Submit
 */
async function onSubmit(): Promise<void> {

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
   * De flash-trigger wordt verhoogd voor alle velden.
   * De child-component toont alleen een flash wanneer er daadwerkelijk
   * een error/warning aanwezig is.
   */
  for (const field of bookingFieldNames) {
    formFlashTriggers.value[field]++;
  }

  const educationHasErrors =
  canShowEducationDetails.value && hasEducationSelectionIssues.value;

  if (educationHasErrors) {
    flashEducationSelectionIssues();
  }

  if (firstError) {
    focusField(firstError);
    return;
  }

  if (educationHasErrors) {
    return;
  }

  const formData = new FormData();

  const fieldsToSend = shouldSendCjpDetails
    ? bookingFieldNames
    : bookingFieldNames.filter(
        (field) => !["cjpContactpersoonNaam", "cjpPasnummer"].includes(field),
      );

  for (const field of fieldsToSend) {
    formData.append(field, formValues.value[field]);
  }

  const educationSelectionPayload = getCurrentEducationSelectionPayload();

  if (educationSelectionPayload) {
    formData.append(
      "educationSelection",
      JSON.stringify(educationSelectionPayload),
    );
  }

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

/**
 * Watchers
 */
watch(
  () => formValues.value.bezoekdatum,
  () => {
    if (!hasValidVisitDate.value) {
      resetFromVisitDateDown();
      clearEducationFlashTriggers();
      return;
    }
   
    resetProgramAndStudentCount();
  },
);

watch(
  () => formValues.value.onderwijsSector,
  (newSector, oldSector) => {
    if (newSector === oldSector) {
      return;
    }

    resetLevelsGroupsProgramAndStudentCount();
    clearEducationFlashTriggers();
  },
);

watch(
  () => selectedLevelsForCurrentSector.value.join("|"),
  () => {
    pruneGroupsForDeselectedLevels();
    resetProgramAndStudentCount();

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

watch(
  () => JSON.stringify(selectedGroupsForCurrentSector.value),
  () => {
    resetProgramAndStudentCount();

    for (const levelKey of Object.keys(educationGroupFlashTriggers.value)) {
      if (!groupSelectionIssues.value[levelKey]) {
        delete educationGroupFlashTriggers.value[levelKey];
      }
    }
  },
);
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
          <span class="main-title__icon" aria-hidden="true">
            <GraduationCap :size="24" :stroke-width="2.5" />
          </span>
          Onderwijs Aanvraagformulier
        </h1>

        <form
          action=""
          class="form-geoform"
          @submit.prevent="onSubmit"
        >
          <fieldset class="fieldset-geoform">
            <legend class="legend-geoform">BASISGEGEVENS</legend>

            <template v-for="field in basisFieldNames" :key="field">
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
              />

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
                  <div v-show="usesCjpDiscount" class="cjp-extra-fields">
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

              <template
                v-else-if="field === 'cjpContactpersoonNaam' || field === 'cjpPasnummer'"
              />

              <!--
                Voorkomt dat CJP-subvelden nogmaals als standaard input renderen.
                Alle overige basisvelden gebruiken de generieke input-component.
              -->
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

              <GeoInfoToggle
                v-if="field === 'contactpersoonTelefoonnummer'"
                id="telefoonInfo"
                label="Meer informatie over de telefoonnummers"
                open-label="Verberg informatie over telefoonnummers"
              >
                <p>
                  <strong>Telefoonnummer van de school:</strong>
                  Het nummer waarop GeoFort de school kan bereiken. Gebruik een vast of mobiel nummer.
                </p>

                <p>
                  <strong>Telefoonnummer contactpersoon:</strong>
                  GeoFort verwacht een mobiel nummer om de contactpersoon te kunnen bereiken.
                </p>
              </GeoInfoToggle>
            </template>
          </fieldset>

          <fieldset
            v-if="bookingProgramConfig"
            class="fieldset-geoform fieldset-geoform--info"
          >
            <legend class="legend-geoform legend-geoform--info">
              PRAKTISCHE INFORMATIE
            </legend>

            <BookingInfoCard :config="bookingProgramConfig" />
          </fieldset>

          <fieldset class="fieldset-geoform">
            <legend class="legend-geoform">LESPROGRAMMA</legend>

            <template v-for="field in programFieldNames" :key="field">
              <GeoFormEducationTypeSelect
                v-if="field === 'onderwijsSector' && bookingProgramConfig"
                :id="BookingFieldConfig.onderwijsSector.id"
                :label="BookingFieldConfig.onderwijsSector.label"
                :options="selectorOptions"
                :programs="bookingProgramConfig.programs"
                :required="BookingFieldConfig[field].required"
                :visit-date="formValues.bezoekdatum"
                :issue="formIssues.onderwijsSector"
                :flash-trigger="formFlashTriggers.onderwijsSector"
                v-model="formValues.onderwijsSector"
                :ref="(el) => setFieldRef('onderwijsSector', el)"
                @blur="singleFieldValidation('onderwijsSector')"
                @change="handleEducationSectorChange"
              />

              <Transition name="education-level-reveal">
                <GeoFormEducationLevelSelector
                  v-if="field === 'onderwijsSector' && canShowEducationDetails"
                  :levels="availableLevelsForSector"
                  v-model:selected-levels="selectedLevelsForCurrentSector"
                  v-model:selected-groups-by-level="selectedGroupsForCurrentSector"
                  :rules="currentLevelSelectionRules"
                  :level-issue="levelSelectionIssue"
                  :group-issues="groupSelectionIssues"
                  :level-flash-trigger="educationLevelFlashTrigger"
                  :group-flash-triggers="educationGroupFlashTriggers"
                />
              </Transition>
            </template>
            
          </fieldset>

          <FormError
            :message="formError"
            @dismiss="clearFormError"
          />

          <GeoBtn :state="state" />
        </form>
      </main>

      <GeoFooter />
    </div>
  </div>
</template>
