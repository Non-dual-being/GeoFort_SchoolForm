<script setup lang="ts">
import { ref, onMounted, type Ref } from 'vue'
import GeoFormInputField from './components/form/GeoFormInputField.vue';
import { validateField, type BookingField } from './validation/booking.ts';
import { useScrollIndicator } from './composables/useScrollindicator';
import { ValidationShape } from './validation/booking.ts';
import './../css/form/index.css'

useScrollIndicator(window);


/**showpage animation */
const pageVisible = ref(false);

onMounted(() => {
    //request esnure that start value false is first seen and then the toglle
    requestAnimationFrame(() => {
        pageVisible.value = true
    })
})

const form = ref({
    schoolnaam: "",
})

const issues = ref<Record<BookingField, ValidationShape>>({
    schoolnaam: {}
})

function validateSchoolnaam(): void {
    issues.value.schoolnaam = validateField("schoolnaam", form.value.schoolnaam)
    flashTrigger.value.schoolnaam++; 
}

//trigger
const flashTrigger = ref<Record<BookingField, number>>({
    schoolnaam: 0
})

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
            @submit.prevent
            >
            <fieldset>
                <legend>BASISGEGEVENS</legend>
                <GeoFormInputField
                    id="schoolnaam"
                    label="Naam school"
                    v-model="form.schoolnaam"
                    :issue="issues.schoolnaam"
                    :flashTrigger="flashTrigger.schoolnaam"
                    error-behavior="auto"
                    :autoDismissMs="3000"
                    required
                    @blur="validateSchoolnaam"
                />
            </fieldset>         
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