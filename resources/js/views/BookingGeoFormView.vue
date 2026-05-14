<script setup lang="ts">
import { ref, onMounted, type ComponentPublicInstance, computed, Ref } from 'vue'

import type { BookingField, CountryCode, InputFieldInstance, PhoneNumberField } from '../types/booking/BookingFieldTypes.ts';

import GeoFormInputField from './../components/form/GeoFormInputField.vue';
import FormError from "./../components/form/FormLevelError.vue"
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue"
import GeoFooter from "../components/layout/AppFooter.vue";

import { 
    validateField,
    validateAll,
    normalizePostcode,
    isCountryCode,
    normalizePhoneNumber
} from "./../config/validation/booking.ts";

import {
    BookingFieldConfig,
    bookingFieldNames,
    createInitialBookingForm,
    countryOptions,
    BookingFormValues,
    isPhoneBookingField
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
    if (field === "postcode"){
        formValues.value.postcode = normalizePostcode(formValues.value.postcode, formValues.value.land as CountryCode)
    }
    formIssues.value[field] = validateField(field, formValues.value[field], formValues.value);
    formFlashTriggers.value[field]++;

}


function handleCountryChange(): void {
  /**
   * Als het land wijzigt, kan de bestaande postcode ineens ongeldig
   * of juist geldig worden. Daarom valideren we postcode opnieuw als
   * daar al iets in staat.
   */
  if (formValues.value.postcode.trim().length > 0) {
    singleFieldValidation("postcode");

    formValues.value.postcode = normalizePostcode(
        formValues.value.postcode,
        formValues.value.land as CountryCode,
    );
  }

  /**
   * Optioneel: als je bij landwissel altijd postcode wilt wissen:
   *
   * formValues.value.postcode = "";
   * formIssues.value.postcode = {};
   */
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

const normalizeField = (field: BookingField, inputValues: Ref<BookingFormValues>): void => {
    if (!isCountryCode(inputValues.value.land)) return;

    const land = inputValues.value.land as CountryCode
    
    if (field === "postcode"){
        formValues.value.postcode = normalizePostcode(
            inputValues.value.postcode,
            land
        )

        return;
    } else if (isPhoneBookingField(field)) {
        const phoneField = field as PhoneNumberField
         formValues.value[field] = normalizePhoneNumber(formValues.value[phoneField]);
    }
}
const postcodePlaceHolder = computed(() => {
    return formValues.value.land === "Nederland"
        ? "4171KG"
        : "9700"
})

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
        formData.append(field, formValues.value[field]);
    }

    const result = await submit(formData) as ApiResponse;

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

                        <GeoFormInputField
                            v-else
                            :id="BookingFieldConfig[field].id"
                            :label="BookingFieldConfig[field].label"
                            :type="BookingFieldConfig[field].type"
                            :required="BookingFieldConfig[field].required"
                            :autocomplete="BookingFieldConfig[field].autocomplete"
                            :placeholder="field === 'postcode' 
                                ? postcodePlaceHolder
                                : BookingFieldConfig[field].placeholder"
                            :issue="formIssues[field]"
                            :flash-trigger="formFlashTriggers[field]"
                            v-model="formValues[field]"
                            :ref="(el) => setFieldRef(field, el)"
                            @blur="singleFieldValidation(field)"
                        />
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