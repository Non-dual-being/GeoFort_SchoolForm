<script setup lang="ts">
import { computed } from 'vue';
import type { SubmitState } from '../../composables/useFormSubmit';

const props = defineProps<{
    state: SubmitState;
    disabled?: boolean;
    disabledReason?: string;
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
        props.disabled === true ||
        ["pending", "slow", "success"].includes(props.state)
    )
})

const isPending = computed(() => {
    return ['slow', 'pending'].includes(props.state)
})
    

</script>

<template>
    <div
        class="submit-wrapper"
        :class="{ 'is-disabled': disabled }"
        :aria-disabled="disabled ? 'true' : undefined"
        :aria-describedby="disabled && disabledReason ? 'submit-disabled-hint' : undefined"
        :tabindex="disabled ? 0 : undefined"
    >
        <button
        type="submit",
        class="btn-primary"
        :class="`btn-primary--${state}`"
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

        <span
            v-if="disabled && disabledReason"
            id="submit-disabled-hint"
            class="submit-wrapper__hint"
        >
            {{ disabledReason }}
        </span>

    </div>
</template>
<style scoped>
.submit-wrapper {
    position: relative;
    display: flex;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

.submit-wrapper.is-disabled {
    cursor: help;
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
    opacity: 0.68;
    cursor: default;
    box-shadow: none;
    transform:  none;
}

.submit-wrapper__hint {
    position: absolute;
    right: 0;
    bottom: calc(100% + 0.55rem);
    z-index: 3;
    max-width: min(18rem, 80vw);
    padding: 0.46rem 0.62rem;
    border: 1px solid rgba(38, 57, 111, 0.18);
    border-radius: var(--field-radius);
    background: rgba(255, 255, 255, 0.98);
    color: var(--color-main-blue-dark);
    font-size: 0.78rem;
    font-weight: 820;
    line-height: 1.25;
    text-align: left;
    box-shadow: 0 0.35rem 0.8rem rgba(8, 21, 64, 0.12);
    opacity: 0;
    pointer-events: none;
    transform: translateY(0.2rem);
    transition:
        opacity 160ms ease,
        transform 180ms ease;
}

.submit-wrapper__hint::after {
    content: "";
    position: absolute;
    right: 1rem;
    top: 100%;
    width: 0.58rem;
    height: 0.58rem;
    border-right: 1px solid rgba(38, 57, 111, 0.18);
    border-bottom: 1px solid rgba(38, 57, 111, 0.18);
    background: rgba(255, 255, 255, 0.98);
    transform: translateY(-50%) rotate(45deg);
}

.submit-wrapper.is-disabled:hover .submit-wrapper__hint,
.submit-wrapper.is-disabled:focus-within .submit-wrapper__hint {
    opacity: 1;
    transform: translateY(0);
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
