<script setup lang="ts">
import { ref, onMounted, type Ref, useTemplateRef } from 'vue'
import GeoFormInputField from './../components/form/GeoFormInputField.vue';
import FormError from "./../components/form/FormLevelError.vue"
import GeoBtn from "./../components/form/GeoFormSubmitButton.vue"
import GeoFooter from "../components/layout/AppFooter.vue";



import { 
    validateField,
    validateAll,
} from '../validation/booking.ts';
import { BookingField } from '../types/form/shared.ts';
import { useScrollIndicator } from '../composables/useScrollindicator.ts';
import { ValidationShape } from '../validation/booking.ts';
import { ApiResponse } from '../types/http/ApiResponse.ts';
import { useFormSubmit } from '../composables/useFormSubmit.ts';

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
const form = ref<Record<BookingField, string>>({
    schoolnaam: "",
})

const issues = ref<Record<BookingField, ValidationShape>>({
    schoolnaam: {}
})

//trigger
const flashTrigger = ref<Record<BookingField, number>>({
    schoolnaam: 0
})

/**-- Per-field validation (onblur) ---------*/
function validateSchoolnaam(): void {
    issues.value.schoolnaam = validateField("schoolnaam", form.value.schoolnaam)
    flashTrigger.value.schoolnaam++; 
}


// GECORRIGEERD: Simpele ref voor element refs
const fieldRefs = ref<Record<BookingField, InstanceType<typeof GeoFormInputField> | null>>({
    schoolnaam: null
})
/** 
 * reactive reference 
 * Binding the template with ref=fielRefs.schoolnaam
 * 
*/

function handleValidationErrors(
    fieldErrors: Partial<Record<BookingField, string>>
): void {
    for (const [key, msg] of Object.entries(fieldErrors)) {
        const field = key as BookingField;
        if (msg) {
            issues.value[field] = { error: msg};
            flashTrigger.value[field]++;
        }
    }
    const firstKey = Object.keys(fieldErrors)[0] as BookingField | undefined;
    if (firstKey) fieldRefs.value[firstKey]?.focus();
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
    } = validateAll(form.value);

    issues.value = validationErrors;

    for (const key of Object.keys(flashTrigger.value) as BookingField[]){
        flashTrigger.value[key]++;
    }
    /**
     * the trigger triggers the useFieldFieldFlash watcher that is conditioned by the presence of a error message
     * The trigger is connected to the GeoFortFormInput component
     */

    if (firstError) {
        const fieldEl = fieldRefs.value?.[firstError]
        if (fieldEl) fieldEl.focus();
        return;
    }

    const formData = new FormData();
    for (const [key, value] of Object.entries(form.value)) {
        formData.append(key, value);
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
                <GeoFormInputField
                    id="schoolnaam"
                    label="Naam school"
                    v-model="form.schoolnaam"
                     :ref="(el) => { fieldRefs.schoolnaam = el as InstanceType<typeof GeoFormInputField>}"
                    data-field="schoolnaam"
                    :issue="issues.schoolnaam"
                    :flashTrigger="flashTrigger.schoolnaam"
                    error-behavior="auto"
                    :autoDismissMs="3000"
                    required
                    @blur="validateSchoolnaam"
                />
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
@import './../../css/form/index.css';

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