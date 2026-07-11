<script setup lang="ts">
import { computed } from "vue";
import type { DisplayedAdminFlash } from "../../composables/useAdminFlash";
import { ADMIN_FLASH_DISMISS_ANIMATION_MS } from "../../flash/flashTiming";

const props = defineProps<{ message: DisplayedAdminFlash }>();
const emit = defineEmits<{ removed: [] }>();
const role = computed(() => props.message.type === "error" ? "alert" : "status");
</script>

<template>
  <Transition :duration="ADMIN_FLASH_DISMISS_ANIMATION_MS" @after-leave="emit('removed')">
    <div
      v-if="!message.dismissing"
      :class="['admin-flash', `admin-flash--${message.type}`]"
      :role="role"
    >
      <strong class="admin-flash__label">
        {{ message.type === "error" ? "Foutmelding" : message.type === "success" ? "Gelukt" : "Informatie" }}
      </strong>
      <span>{{ message.message }}</span>
    </div>
  </Transition>
</template>
