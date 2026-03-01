<script setup lang="ts">
import { ref, onMounted, type Ref, useTemplateRef } from 'vue'
import GeoFormInputField from './components/form/GeoFormInputField.vue';
import GeoBtn from "./components/form/GeoFormSubmitButton.vue"
import { 
    validateField,
    validateAll,
    type BookingField 
} from './validation/booking.ts';
import { useScrollIndicator } from './composables/useScrollindicator';
import { ValidationShape } from './validation/booking.ts';
import { useFormSubmit } from './composables/useFormSubmit.ts';
import './../css/form/index.css'

useScrollIndicator(window);


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

// -- Field element refs for focus-on-error --------------------
const fieldRefs = 
    useTemplateRef<Record<BookingField, InstanceType<typeof GeoFormInputField>>>("fieldRefs")
/** 
 * reactive reference 
 * Binding the template with ref=fielRefs.schoolnaam
 * 
*/

//-- Sumbit ---------------------------------------------------
const { state, serverError, submit } = useFormSubmit();

async function onSubmit(e: Event): Promise<void> {
    const { issues: validationErrors, firstError } = validateAll(form.value);
    issues.value = validationErrors

    for (const key of Object.keys(flashTrigger.value) as BookingField[]){
        flashTrigger.value[key]++;
    }

    if (firstError) {
        const fieldEl = fieldRefs.value?.[firstError]
        if (fieldEl) fieldEl.focus();
        return;
    }

    const formData = new FormData();
    for (const [key, value] of Object.entries(form.value)) {
        formData.append(key, value);
    }

    const result = await submit(formData);

    if ("fieldErrors" in result && result.fieldErrors) {
        for (const [key, msg] of Object.entries(result.fieldErrors)) {
            const field = key as BookingField;
            issues.value[field] = { error: msg };
            flashTrigger.value[field]++;
        }

        const firstServerError = Object.keys(result.fieldErrors)[0] as BookingField;
        fieldRefs.value?.[firstServerError]?.focus();
        return;
    }

}


</script>

<template>
    <main 
        class="page"
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
                    ref="fieldRefs.schoolnaam"
                    :issue="issues.schoolnaam"
                    :flashTrigger="flashTrigger.schoolnaam"
                    error-behavior="auto"
                    :autoDismissMs="3000"
                    required
                    @blur="validateSchoolnaam"
                />
            </fieldset>        
            <GeoBtn
                :state="state"
            /> 
        </form>
    </main>
</template>



<style scoped>
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