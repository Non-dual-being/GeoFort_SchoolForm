<script setup lang="ts">
import { computed } from "vue";
import type { ErrorBehavior } from "../../composables/useFieldFlash";

const props = withDefaults(
  defineProps<{
    id: string;
    msg: string | null;
    visible: boolean;
    hasError: boolean;
    hasWarning: boolean;
    behavior?: ErrorBehavior;
  }>(),
  {
    behavior: "persistent",
  },
);

const shouldShow = computed(() => {
  return (
    props.visible &&
    Boolean(props.msg) &&
    (props.hasError || props.hasWarning)
  );
});

const role = computed(() => {
  return props.hasError ? "alert" : "status";
});

const ariaLive = computed(() => {
  return props.hasError ? "assertive" : "polite";
});

const transitionName = computed(() => {
  return props.behavior === "auto" ? "field-flash-float" : "field-flash-flow";
});
</script>

<template>
  <div
    class="field-flash-host"
    :class="{
      'field-flash-host--persistent': behavior === 'persistent',
      'field-flash-host--auto': behavior === 'auto',
    }"
  >
    <Transition :name="transitionName">
      <div
        v-if="shouldShow"
        :id="`${id}-issue`"
        class="field-flash"
        :class="{
          'field-flash--error': hasError,
          'field-flash--warning': hasWarning && !hasError,
          'field-flash--persistent': behavior === 'persistent',
          'field-flash--auto': behavior === 'auto',
        }"
        :role="role"
        :aria-live="ariaLive"
      >
        <div class="field-flash__inner">
          {{ msg }}
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.field-flash-host {
  width: 100%;
}

/**
 * Persistent:
 * - onderdeel van de layout;
 * - komt onder de input;
 * - duwt de rest van het formulier naar beneden.
 */
.field-flash-host--persistent {
  display: flex;
  flex-direction: column;
  margin-top: var(--flash-y-offset-base-persistent-mode, 0.5rem);
}

/**
 * Auto:
 * - zwevend;
 * - neemt geen ruimte in;
 * - positioneert relatief aan de dichtstbijzijnde parent met position: relative.
 *
 * Zet daarom op je input-wrapper of .field:
 * position: relative;
 */
.field-flash-host--auto {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(var(--field-height-flash-offset, -3.5rem) + var(--flash-y-offset-base-auto-mode, -2rem));
  z-index: var(--flash-z-index, 20);
  pointer-events: none;
}

/**
 * Algemene flash basis.
 */
.field-flash {
  width: 100%;
  overflow: hidden;
}

.field-flash__inner {
  margin-top: var(--flash-margin-top, 0.22rem);
  padding: var(--flash-padding, 0.8rem 0.65rem);
  border-radius: var(--flash-radius-base, 0.6rem);
  text-align: start;
  font-size: var(--flash-fontsize-base, clamp(0.75rem, 1.5vw, 1rem));
  line-height: 1.35;

  /**
   * Belangrijk:
   * Dit gebruikt weer jouw oude mooie flash styling.
   */
  color: var(--flash-text-color, #ffffff);
  background: var(--flash-bg-gradient);
  border: var(--flash-border);
  box-shadow: var(--flash-box-shadow);
}

/**
 * Error/warning veranderen alleen accentkleuren.
 * Ze overschrijven dus NIET meer standaard de hele achtergrond.
 */
.field-flash--error .field-flash__inner {
  border-color: var(--flash-error-border-color, var(--color-main-blue-dark));
}

.field-flash--warning .field-flash__inner {
  border-color: var(--flash-warning-border-color, #d89b00);
}

/**
 * Als je later wél aparte gradients wilt per type, kan dat via tokens:
 *
 * --flash-error-bg-gradient
 * --flash-warning-bg-gradient
 */
.field-flash--error .field-flash__inner {
  background: var(--flash-error-bg-gradient, var(--flash-bg-gradient));
}

.field-flash--warning .field-flash__inner {
  background: var(--flash-warning-bg-gradient, var(--flash-bg-gradient));
}

/**
 * Persistent transition:
 * valt soepel open/dicht in de layout.
 */
.field-flash-flow-enter-active,
.field-flash-flow-leave-active {
  transition:
    max-height 280ms ease,
    opacity 220ms ease,
    transform 260ms ease;
}

.field-flash-flow-enter-from,
.field-flash-flow-leave-to {
  max-height: 0;
  opacity: 0;
  transform: translateY(-0.25rem);
}

.field-flash-flow-enter-to,
.field-flash-flow-leave-from {
  max-height: 7rem;
  opacity: 1;
  transform: translateY(0);
}

/**
 * Auto transition:
 * lijkt meer op je oude zwevende flash.
 */
.field-flash-float-enter-active,
.field-flash-float-leave-active {
  transition:
    opacity 420ms ease,
    transform 420ms ease;
}

.field-flash-float-enter-from {
  opacity: 0;
  transform: translateY(var(--flash-y-offset-transition, 1.2rem));
}

.field-flash-float-enter-to {
  opacity: 1;
  transform: translateY(0);
}

.field-flash-float-leave-from {
  opacity: 1;
  transform: translateY(0);
}

.field-flash-float-leave-to {
  opacity: 0;
  transform: translateX(18px);
}

@media (prefers-reduced-motion: reduce) {
  .field-flash-flow-enter-active,
  .field-flash-flow-leave-active,
  .field-flash-float-enter-active,
  .field-flash-float-leave-active {
    transition: none;
  }
}
</style>