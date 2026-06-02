<script setup lang="ts">
import { GraduationCap } from "lucide-vue-next";
import { ref, onMounted, type ComponentPublicInstance, computed, Ref } from 'vue'

import type { 
    BookingField, 
    CountryCode, 
    InputFieldInstance, 
    PhoneNumberField,
    CountryDependentField,
    CJPFields,
} from '../types/booking/BookingFieldTypes.ts';

import GeoFormInputField from './../components/form/GeoFormInputField.vue';
import FormError from "./../components/form/FormLevelError.vue"
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue"
import GeoInfoToggle from "../components/form/GeoInfoToggles.vue"
import GeoFooter from "../components/layout/AppFooter.vue";
import GeoBookingDateField from "./../components/form/GeoFormBookingDateField.vue";
import GeoDiscoverySelectField from "./../components/form/GeoFormDiscoveryField.vue"
import GeoFormRadioGroup from '../components/form/GeoFormRadioGroup.vue';


import { 
    validateField,
    validateAll,
    normalizePostcode,
    isCountryCode,
    normalizePhoneNumber,
    normalizeEmail,
    geofortDiscoverySelectOptions,
    otherOption,
    normalizeGeoFortDiscovery,
    normalizeCjpPersonName,
    normalizeCjpPasnumber

} from "./../config/validation/booking.ts";

import {
    BookingFieldConfig,
    createInitialBookingForm,
    countryOptions,
    isPhoneBookingField,
    getPlaceHolder,
} from "./../config/booking/BookingFields.ts"

import {
    createInitialIssues,
    createInitialFlashTriggers,
    createInitialFieldRefs
} from "./../config/booking/BookingFormState.ts"

import {
    bookingFieldNames,
    cjpUsageOptions
} from "./../config/booking/BookingFieldConstants.ts"

import { useScrollIndicator } from '../composables/useScrollindicator.ts';
import { ApiResponse } from '../types/http/ApiResponse.ts';
import { useFormSubmit } from '../composables/useFormSubmit.ts';
import GeoFormSelectField from '../components/form/GeoFormSelectFied.vue';



useScrollIndicator(window);

const emit = defineEmits<{
    success: [];
    "server-error": [{message: string}];
}>();


/** -----------showpage animation ------- */
const pageVisible = ref(false);

onMounted(() => {
    //request esnure that start value false is first seen and then the toglle
    requestAnimationFrame(() => {
        pageVisible.value = true
    })
})

/** -Form State ----------------------------------------------- */
const formValues = ref(createInitialBookingForm());
const formIssues = ref(createInitialIssues());
const formFlashTriggers = ref(createInitialFlashTriggers());
const formFieldRefs = ref(createInitialFieldRefs());



function singleFieldValidation(field: BookingField): void {
    normalizeField(field);
    formIssues.value[field] = validateField(field, formValues.value[field], formValues.value);
    formFlashTriggers.value[field]++;

}


function handleCountryChange(): void {
    const fieldsToValidate: CountryDependentField[] = [
            "postcode",
            "schoolTelefoonnummer",
            "contactpersoonTelefoonnummer"
    ];

    for (const field of fieldsToValidate) {
        if (formValues.value[field].trim().length === 0) {
            continue;
        }

        normalizeField(field);

        formIssues.value[field] = validateField(
            field,
            formValues.value[field],
            formValues.value
        );

        formFlashTriggers.value[field]++;

    }
}


function setFieldRef(
    field: BookingField,
    el: Element | ComponentPublicInstance | null
): void {
    formFieldRefs.value[field] = el as InputFieldInstance | null;
}

function focusField(field: BookingField): void {
    formFieldRefs.value[field]?.focus();
}


function handleValidationErrors(
    fieldErrors: Partial<Record<BookingField, string>>
): void {
    let firstKey: BookingField | null = null;

    for (const field of bookingFieldNames){
        const msg = fieldErrors[field];

        if (msg) {
            formIssues.value[field] = { error: msg };
            formFlashTriggers.value[field]++;

            if (!firstKey) {
                firstKey = field;
            }
        }

    }

    if (firstKey){
        focusField(firstKey);
    }

}

const normalizeField = (field: BookingField): void => {
  if (field === "postcode") {
    if (!isCountryCode(formValues.value.land)) return;

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

    return;
  }
};

const returnPlaceholder = (field: BookingField, country: string): string => {
    const land = isCountryCode(country)
        ? country
        : "Nederland";

    return getPlaceHolder(field, land)
}

function handleFieldUpdate(field: BookingField, value: string): void {
  formValues.value[field] = value;

  /**
   * Als er al een melding stond, herberekenen we direct.
   * Zo verdwijnt "Vul bezoekdatum in" meteen zodra de gekozen datum geldig is.
   *
   * We verhogen hier bewust niet altijd de flash trigger.
   * Anders gaat de melding steeds opnieuw flitsen terwijl de gebruiker typt/kiest.
   */
    if (formIssues.value[field]?.error || formIssues.value[field]?.warning) {
        formIssues.value[field] = validateField(
        field,
        value,
        formValues.value,
        );
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

  if (value !== "ja") {
    formValues.value.cjpContactpersoonNaam = "";
    formValues.value.cjpPasnummer = "";

    formIssues.value.cjpContactpersoonNaam = {};
    formIssues.value.cjpPasnummer = {};
  }
}

//-- Sumbit ---------------------------------------------------
const { 
    state,
    formError,
    submit,
    clearFormError
} = useFormSubmit();

const usesCjpDiscount = computed(() => formValues.value.cjpPasGebruik === "ja");




async function onSubmit(): Promise<void> {
    // rename initial issues value to validationErrors
    //normalise first and then validate

    for (const field of bookingFieldNames){
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
        firstError 
    } = validateAll(formValues.value);

    formIssues.value = validationErrors

    for (const key of bookingFieldNames){

        formFlashTriggers.value[key]++
    }

    /**
     * the trigger triggers the useFieldFieldFlash watcher that is conditioned by the presence of a error message
     * The trigger is connected to the GeoFortFormInput component
     */

    if (firstError) {
        focusField(firstError);
        return;
    }

    const formData = new FormData();

    const sendData = shouldSendCjpDetails 
        ? bookingFieldNames
        : bookingFieldNames.filter((field) =>  !["cjpContactpersoonNaam", "cjpPasnummer"].includes(field))
    
    for (const field of sendData){
        formData.append(field, formValues.value[field]);
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

        //composable handles rate-limit logic
        case "rate-limit":
            return;

        case "server":
            /**the state value check is to prevent a trigger on a netwok error and thus ensuring a pure server error */
            if (state.value === "error") {
                emit("server-error", {
                message: "Het online formulier liep tegen een kritieke fout aan"
            });
            }
        return;
    }
}
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
                        
                        <template v-for="field in bookingFieldNames" :key="field">
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
                                :id = "BookingFieldConfig[field].id"
                                :label = "BookingFieldConfig[field].label"
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
                                v-else-if="
                                    field === 'cjpContactpersoonNaam' || field === 'cjpPasnummer'
                                "
                            />

                            <!--To prevent fields from rendering as default Inputfield-->

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
                    <FormError
                        :message="formError"
                        @dismiss="clearFormError" 
                    />
                    <GeoBtn
                        :state="state"
                    /> 
                </form>
            </main>
            <GeoFooter />
        </div>
    </div>
</template>
