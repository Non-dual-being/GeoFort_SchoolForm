<script setup lang="ts">
defineProps<{
    id: string,
    msg: string | null;
    visible: boolean;
    hasError: boolean;
    hasWarning: boolean;
}>()
</script>

<template>
    <div class="flash-container">
        <transition name="field-flash">
            <div
                v-if="visible && (hasError || hasWarning)"
                class="flash-message-base"
                :id="`${id}-error`"
                role="alert"
            >
            {{ msg }}
            </div>
        </transition>
    </div>
</template>

<style scoped>
    .flash-container {
        position: relative;
        width: 100%;
        display: flex;
        flex-direction: column;
    }

    .flash-message-base {
        position: absolute;
        top: var(--flash-y-offset-base); /* Startpositie relatief aan container */
        left: 0;
        width: 100%;
        z-index: 5;
        
        padding: 0.8rem 0.5rem;
        border-radius: var(--flash-radius-base);
        text-align: start;
        
        /* Jouw custom styling */
        background: var(--flash-bg-gradient);
        border: var(--flash-border);
        box-shadow: var(--flash-box-shadow);
        color: var(--flash-text-color);

        

        pointer-events: none;
        opacity: 1;
    }

        .field-flash-enter-active,
    .field-flash-leave-active {
        transition: all 0.5s ease-in-out;
    }

    .field-flash-enter-from,
    .field-flash-leave-to {
        opacity: 0;
    }

    .field-flash-enter-from{
        transform: translateY(var(--flash-y-offset-transition));
    }

    .field-flash-leave-to {
    opacity: 0;
    transform: translateX(18px);
    }





    /**
        **Naming convention enter-actieve etc
        *todo: with transitions, use name conventions to make it work
        .field-flash-leave-from
    */
</style>