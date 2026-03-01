<script setup lang="ts">
import { computed } from 'vue';
import type { SubmitState } from '../../composables/useFormSubmit';

const props = defineProps<{
    state: SubmitState;
}>();

const label = computed(() => {
    switch (props.state) {
        case "pending"  : return "Verzenden";
        case "slow"     : return "Even geduld..";
        case "success"  : return "Verzonden!";
        case "error"    : return "Fout in de verzending";
        default: return "Verstuur aanvraag";
    }
})

const isDisabled = computed(() => {
    return (
        ["pending", "slow", "success"].includes(props.state)
    )
})

const isPending = computed(() => {
    return ['slow', 'pending'].includes(props.state)
})
    

</script>

<template>
    <div class="submit-wrapper">
        <button
        type="submit",
        class="btn-primary"
        :class="`btn=primary--${state}`"
        :disabled="isDisabled"
        :aria-busy="isPending"          
    >
    <span 
        v-if="isPending"
        class="btn-spinner"
        aria-hidden="true"
    ></span>
        {{ label }}
    </button>

    </div>
</template>
<style scoped>
.submit-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

.btn-primary {
    display:         inline-flex;
    align-items:     center;
    gap:             0.6em;
    padding:         var(--btn-primary-padding-v) var(--btn-primary-padding-h);
    background:      var(--btn-primary-bg);
    color:           var(--btn-primary-color);
    border:          var(--btn-primary-border);
    border-radius:   var(--btn-primary-radius);
    font-family:     var(--btn-primary-font);
    font-size:       var(--btn-primary-font-size);
    font-weight:     var(--btn-primary-font-weight);
    letter-spacing:  var(--btn-primary-letter-spacing);
    cursor:          pointer;
    box-shadow:      var(--btn-primary-shadow);
    transition:      var(--btn-primary-transition);
    text-transform:  uppercase;
}

/* -- Staten ------------------------------------------ */
.btn-primary:hover:not(:disabled) {
    background:  var(--btn-primary-bg-hover);
    box-shadow:  var(--btn-primary-shadow-hover);
    transform:   translateY(-1px);
}

.btn-primary:active:not(:disabled) {
    background:  var(--btn-primary-bg-active);
    box-shadow:  var(--btn-primary-shadow-active);
    transform:   translateY(0);
}

.btn-primary:focus-visible {
    outline: none;
    border:  var(--btn-primary-border-focus);
}

.btn-primary--pending,
.btn-primary--slow {
    background: var(--btn-primary-bg-pending);
    color:      var(--btn-primary-color-pending);
    cursor:     not-allowed;
}

.btn-primary--success {
    background: var(--btn-primary-bg-success);
    cursor:     default;
}

.btn-primary--error {
    background: var(--btn-primary-bg-error);
}

.btn-primary:disabled {
    box-shadow: none;
    transform:  none;
}

/* -- Spinner ----------------------------------------- */
.btn-spinner {
    display:       inline-block;
    width:         var(--btn-spinner-size);
    height:        var(--btn-spinner-size);
    border:        var(--btn-spinner-border);
    border-top:    var(--btn-spinner-border-top);
    border-radius: 50%;
    animation:     spin var(--btn-spinner-speed) linear infinite;
    flex-shrink:   0;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* -- Reduced motion ---------------------------------- */
@media (prefers-reduced-motion: reduce) {
    .btn-primary {
        transition: none;
    }
    .btn-spinner {
        animation: none;
        opacity: 0.6;
    }
}
</style>