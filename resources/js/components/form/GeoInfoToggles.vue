<script setup lang="ts">
import { computed, ref } from 'vue';
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        openLabel?: string;
    }>(),
    {
        openLabel: "Verberg informatie"
    }
);

const isOpen = ref(false);
const buttonLabel = computed(() => {
    return isOpen.value ? props.openLabel : props.label;
})

function toggle(): void {
    isOpen.value = !isOpen.value;
}
</script>

<template>
  <div class="geo-info-toggle">
    <button 
        class="geo-info-toggle__button"
        type="button"
        :aria-expanded="isOpen"
        :aria-controls="id"
        @click="toggle"
        >
        <span>{{ buttonLabel }}</span>
        <span 
            class="geo-info-toggle__icon"
            aria-hidden="true"
            >
            {{ isOpen ? '-' : '+' }}
        </span>
    </button>
    <Transition name="geo-info-toggle">
        <div 
            v-show="isOpen"
            :id="id" 
            class="geo-info-toggle__content"
            >
                <slot />
        </div>
    </Transition>
  </div>  
</template>