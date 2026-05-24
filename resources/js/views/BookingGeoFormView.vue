<script setup lang="ts">
import { ref, onMounted, type ComponentPublicInstance, computed, Ref } from 'vue'

import type { 
    BookingField, 
    CountryCode, 
    InputFieldInstance, 
    PhoneNumberField,
    InputMode,  
    CountryDependentField
} from '../types/booking/BookingFieldTypes.ts';

import GeoFormInputField from './../components/form/GeoFormInputField.vue';
import FormError from "./../components/form/FormLevelError.vue"
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue"
import GeoInfoToggle from "../components/form/GeoInfoToggles.vue"
import GeoFooter from "../components/layout/AppFooter.vue";
import GeoBookingDateField from "./../components/form/GeoFormBookingDateField.vue";


import { 
    validateField,
    validateAll,
    normalizePostcode,
    isCountryCode,
    normalizePhoneNumber,
    normalizeEmail
} from "./../config/validation/booking.ts";

import {
    BookingFieldConfig,
    bookingFieldNames,
    createInitialBookingForm,
    countryOptions,
    isPhoneBookingField,
    getPlaceHolder
} from "./../config/booking/BookingFields.ts"

import {
    createInitialIssues,
    createInitialFlashTriggers,
    createInitialFieldRefs
} from "./../config/booking/BookingFormState.ts"

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
    if (!isCountryCode(formValues.value.land)) return;

    const land = formValues.value.land as CountryCode
    
    if (field === "postcode"){
        formValues.value.postcode = normalizePostcode(
            formValues.value.postcode,
            land
        )

        return;
    } else if (isPhoneBookingField(field)) {
        const phoneField = field as PhoneNumberField
        formValues.value[field] = normalizePhoneNumber(formValues.value[phoneField]);
    } else if (field === "email"){
        formValues.value.email = normalizeEmail(formValues.value.email)
    }
}


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

//-- Sumbit ---------------------------------------------------
const { 
    state,
    formError,
    submit,
    clearFormError
} = useFormSubmit();


async function onSubmit(): Promise<void> {
    // rename initial issues value to validationErrors
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
    
    for (const field of bookingFieldNames){
        normalizeField(field);
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

    <div class="app-shell">
        <main 
            class="page main"
            ref="scrollContainer"
            :class="{ 'page--visible': pageVisible }"
            >
            <h1 class="main-title">ONDERWIJS AANVRAAGFORMULIER</h1>
            <form 
                action="" 
                class="main-form"
                @submit.prevent="onSubmit"
                >
                <fieldset>
                    <legend>BASISGEGEVENS</legend>
                    
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
</template>



<style scoped>


.app-shell {
    display: flex;
    flex-direction: column;
    min-height: 100dvh;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}
.page {
  opacity: 0;
  transform: translateY(10px) translateZ(0);
  will-change: transform, opacity; /**vloeinde anumatie hulp */
 
}

.page.page--visible {
  animation: showpage 600ms ease forwards;
}


.main-title {
  color: var(--color-main-red);
  font:  var(--fs-big) var(--font-headings);
  margin: 0 0 32px;
  font-weight: 600;
  text-align: center;
  max-width: 100%;

}

.main-form {
    background-image: url("./assets/images/form-heightlines.png");

}

fieldset {
  margin-bottom: 20px;
  border: 1px solid black;
  padding: 30px;
  border-radius: 3px;
  display: flex;
  flex-direction: column;
  
}

/**
    *--------------------------------*
            MEDIA QUUERIES
    *--------------------------------*

*/

@media (prefers-reduced-motion: reduce) {
    .page {
        opacity: 1;
        transform: none;
        animation: none;
    }
    
}
</style>