<script setup lang="ts">
import { useFlash } from './useFlash';
const {
    flashMessage,
    flashType,
    isVisible
} = useFlash();
</script>

<template>
    <div
        class="flash-container"
    >
    <transition name="slide-fade">
        <div
            v-if="isVisible && flashMessage"
            class="flash-message-basic flash-message-active-general"
        >
            {{ flashMessage }}
        </div>
    </transition>

    </div>

</template>

<style scoped>
    .flash-container {
        position: relative;
        display: flex;
        justify-content: start;
        align-items: center;
        width: 100%;
        height: auto;
        z-index: 2;
    }

    .flash-message-basic {
        position: absolute;
        z-index: 3;   
        transition: var(--flash-box-shadow-message-general);
        font-size: var(--flash-font-size-message-general);
    }

    .flash-message-active-general {
        box-shadow:var(--flash-box-shadow-message-general);

    }

</style>