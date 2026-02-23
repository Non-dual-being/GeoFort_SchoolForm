<script setup lang="ts">
import type { SubmitState } from '../../composables/useFormSubmit';

const props = defineProps<{
    state: SubmitState;
    label?: string;
}>();

const LABELS: Record<SubmitState, string> = {
    idle: "Verzenden",
    pending: "Aanvraag wordt verwerkt...",
    slow: "De aanvraag verwerken duurt langer dan verwacht...",
    error: "Verzenden",
    success: "Verzenden"
}

const isDisabled = (state: SubmitState): boolean => {
    return ["pending", "slow", "success"].includes(state)
}

const isPending = (state: SubmitState): boolean => {
    return ['slow', 'pending'].includes(state)
}
</script>

<template>
    <button
        type="submit",
        class="submit-btn"
        :class="{
            'submit-btn--pending' : isPending(state),
            'submit-btn--error'   : state === 'error',
            'submit-btn-success'  : state === 'success' 
        }"
        :disabled="isDisabled(state)"
        :aria-busy="isPending(state)"          
    >
    <span 
        v-if="isPending(state)"
        class="submit-btn__spinner"
        aria-hidden="true"
    ></span>
        {{ props.label ?? LABELS[state] }}
    </button>
</template>
<style scoped>
.submit-btn {
    padding: 0.75rem 2rem;
    font: 600 var(--fs-base) var(--font-headings);
    background-color: var(--color-main-red);
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: background-color 0.2s, opacity 0.2s;
}

.submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.submit-btn--success {
    background-color: var(--color-success, #2e7d32);
}

.submit-btn--error {
    background-color: var(--color-main-red);
}

.submit-btn__spinner {
    width: 1rem;
    height: 1rem;
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    flex-shrink: 0;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .submit-btn__spinner {
        animation: none;
        opacity: 0.6;
    }
}
</style>