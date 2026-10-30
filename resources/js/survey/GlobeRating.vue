<script setup lang="ts">
import { ratings, type Satisfaction } from "./policy";
defineProps<{ modelValue: Satisfaction | null; disabled?: boolean }>();
defineEmits<{ "update:modelValue": [value: Satisfaction] }>();
</script>

<template>
  <fieldset class="globe-rating">
    <legend>Hoe hebben jullie het bezoek ervaren?</legend>
    <div class="globe-rating__choices">
      <label v-for="rating in ratings" :key="rating.value" class="globe-rating__choice" :class="{ 'is-selected': modelValue === rating.value }">
        <input type="radio" name="satisfaction" :value="rating.value" :checked="modelValue === rating.value" :disabled="disabled" required @change="$emit('update:modelValue', rating.value)">
        <svg viewBox="0 0 100 100" aria-hidden="true">
          <circle cx="50" cy="50" r="44" fill="#dceefa" stroke="#346e91" stroke-width="2" />
          <path d="M19 22 35 11 48 12 44 27 33 32 29 47 16 43 10 37ZM64 12 77 21 83 38 70 42 64 32 54 28ZM13 55 26 54 32 66 23 78 17 72ZM63 79 77 69 88 58 87 73 73 87 61 91Z" fill="#8bbda7" opacity=".7" />
          <circle cx="35" cy="46" r="3.5" fill="#102454" /><circle cx="65" cy="46" r="3.5" fill="#102454" />
          <path v-if="rating.value === 'dissatisfied'" d="M35 69 Q50 54 65 69" fill="none" stroke="#102454" stroke-width="3.5" stroke-linecap="round" />
          <path v-else-if="rating.value === 'neutral'" d="M36 65 H64" fill="none" stroke="#102454" stroke-width="3.5" stroke-linecap="round" />
          <path v-else d="M32 59 Q50 82 68 59 Z" fill="white" stroke="#102454" stroke-width="3.5" stroke-linejoin="round" />
        </svg>
        <span>{{ rating.label }}</span>
      </label>
    </div>
  </fieldset>
</template>

<style scoped>
.globe-rating { border: 0; padding: 0; margin: 0; min-width: 0; }
legend { font-weight: 700; font-size: 1.2rem; margin-bottom: 1.2rem; }
.globe-rating__choices { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: .75rem; }
.globe-rating__choice { position: relative; display: grid; justify-items: center; gap: .55rem; border: 2px solid #dbe4ef; border-radius: 1rem; padding: 1rem .35rem; cursor: pointer; background: white; font-weight: 650; }
.globe-rating__choice:hover { background: #f0f6fc; border-color: #87a8cd; }
.globe-rating__choice.is-selected { border-color: #173c71; background: #eaf3ff; box-shadow: 0 0 0 1px #173c71; }
.globe-rating__choice:focus-within { outline: 3px solid #80a8dc; outline-offset: 3px; }
svg { width: min(100%,110px); }
input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; width: 100%; height: 100%; }
@media (max-width: 420px) { .globe-rating__choices { gap: .4rem; } .globe-rating__choice { font-size: .8rem; } }
@media (forced-colors: active) { input { position: static; opacity: 1; width: auto; height: auto; } }
</style>
