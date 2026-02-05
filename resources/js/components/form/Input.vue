<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    id: string;
    label: string;
    modelValue: string | number; //v-model support
    error?: string | null;
    type?: string;
    placeholder?: string;
    required?: boolean;
}>();

const emit = defineEmits(['update:modelValue', 'blur']);
const hasError = computed(() => !!props.error && props.error.length);
</script>

<template>
    <div class="flash-container">
        <transition name="flash-show">
            <div
                v-if="hasError"
                class="flash-message-base"
                :id="`${id}Fout`"
                {{ error }}
            >
            </div>
        </transition>
    </div>
    <label 
        :for="id"
        class="input-label"
    >{{ label }}</label>

    <input
        :id="id"
        :type="type || 'text'"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"

        class="form-input"
        :class="{ 'has-error' : hasError }"

        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        @blur="emit('blur')"
    >
</template>

<style scoped>
    .flash-container {
        position: relative;
        width: 100%;
        display: flex;
        flex-direction: column;
    }

    /* 2. De Input Styling */
    .form-input {
        width: 100%;
        background-color: white;
        border: 2px solid var(--color-geo-blue-dark);
        transition: border-color 0.3s, background-color 0.3s;
    }

    .form-input.has-error {
        border-color: var(--flash-error-input-border);
        background-color: var(--flash-error-input-border);
    }

    .input-label {
        padding-bottom: 0.5rem;
        font-weight: bold;
        color: var(--color-geo-blue-dark);
    }

    .flash-message-base {
        position: absolute;
        top: 0; /* Startpositie relatief aan container */
        left: 0;
        width: 100%;
        z-index: 5;
        
        padding: 0.8rem 0.5rem;
        border-radius: var(--border-radius-base);
        text-align: start;
        
        /* Jouw custom styling */
        background: var(--flash-bg-gradient);
        border: var(--flash-border);
        box-shadow: var(--flash-box-shadow);
        color: var(--flash-text-color);
    }

    .flash-show,
    .flash-hide {
        transition: all 0.5s ease-in-out;
    }

    .flash-show-start,
    .flash-hide-end {
        opacity: 0;
        transform: translateY(0px); /* Start op de plek van de input */
    }

    .flash-show-end,
    .flash-hide-start {
        opacity: 1;
        transform: translateY(-45px); /* Schuif omhoog boven het label */
    }
</style>