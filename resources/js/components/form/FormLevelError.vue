<script setup lang="ts">
defineProps<{message: string | null}>();
const emit = defineEmits<{ dismiss: [] }>();

/**
 * component is allowed to send "dismiss" event
 * [] no payload is allowed
 * the [] is a tuple type to indicate the emit event and the payload
 * with payload dismiss: [reason: string]
 */


</script>
<template>
    <transition name="form-error">
        <div
            v-if="message"
            class="form-error"
            role="alert"
        >
            <span>{{ message }}</span>
            <button
                type="button"
                @click="emit('dismiss')"
                aria-label="Sluiten"
            >
            x
            </button>
        </div>
    </transition>
</template>

<style scoped>
.form-error {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    background: var(--flash-bg-gradient);
    border: var(--flash-border);
    border-radius: var(--flash-radius-base);
    color: var(--flash-text-color);
}
.form-error button {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: inherit;   
}

.form-error-enter-active,
.form-error-leave-active {
  transition: all 0.3s ease;
}

.form-error-enter-from,
.form-error-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}
</style>