<script setup lang="ts">
import { computed, ref } from "vue";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    openLabel?: string;
  }>(),
  {
    openLabel: "Verberg informatie",
  },
);

const isOpen = ref(false);

const buttonId = computed(() => `${props.id}-button`);

const buttonLabel = computed(() => {
  return isOpen.value ? props.openLabel : props.label;
});

function toggle(): void {
  isOpen.value = !isOpen.value;
}

/**
 * TRANSITION UITLEG
 *
 * Vue's <Transition> doorloopt bij openen en sluiten een aantal fases.
 *
 * OPENEN:
 *
 * 1. beforeEnter()
 *    Dit gebeurt vóórdat het element zichtbaar gaat worden.
 *    Hier zetten we de starttoestand:
 *
 *    - height: 0
 *    - opacity: 0
 *
 *    Daardoor begint het paneel volledig dicht.
 *
 * 2. enter()
 *    Dit gebeurt zodra Vue het element wil tonen.
 *    Hier meten we de echte hoogte van de inhoud met:
 *
 *    - element.scrollHeight
 *
 *    Daarna zetten we:
 *
 *    - height: `${element.scrollHeight}px`
 *    - opacity: 1
 *
 *    CSS ziet daardoor een overgang van:
 *
 *    - height: 0px naar bijvoorbeeld height: 160px
 *
 *    Dat kan CSS wél animeren. CSS kan namelijk niet goed animeren van
 *    height: 0 naar height: auto.
 *
 * 3. afterEnter()
 *    Dit gebeurt nadat de open-animatie klaar is.
 *    Hier zetten we height op "auto".
 *
 *    Waarom?
 *    Na de animatie wil je niet vast blijven zitten op bijvoorbeeld 160px.
 *    Als de tekst later verandert, of de viewport verandert, moet het paneel
 *    natuurlijk flexibel kunnen meegroeien.
 *
 *
 * SLUITEN:
 *
 * 4. beforeLeave()
 *    Dit gebeurt voordat het element dichtgaat.
 *    We zetten de huidige echte hoogte expliciet als pixelwaarde:
 *
 *    - height: `${element.scrollHeight}px`
 *
 *    Daarna forceren we een browser reflow met:
 *
 *    - void element.offsetHeight
 *
 *    Dat zorgt ervoor dat de browser deze beginhoogte echt registreert.
 *
 * 5. leave()
 *    Nu zetten we:
 *
 *    - height: 0
 *    - opacity: 0
 *
 *    CSS animeert daardoor van bijvoorbeeld 160px naar 0px.
 *
 * 6. afterLeave()
 *    Na het sluiten ruimen we inline styles op.
 *
 *
 * WAAROM NIET GEWOON CSS height: auto?
 *
 * CSS kan niet vloeiend rekenen tussen:
 *
 * - height: 0
 * - height: auto
 *
 * Daarom gebruiken we tijdelijk een concrete pixelhoogte via scrollHeight.
 */
function beforeEnter(el: Element): void {
  const element = el as HTMLElement;

  element.style.height = "0";
  element.style.opacity = "0";
}

function enter(el: Element): void {
  const element = el as HTMLElement;

  requestAnimationFrame(() => {
    element.style.height = `${element.scrollHeight}px`;
    element.style.opacity = "1";
  });
}

function afterEnter(el: Element): void {
  const element = el as HTMLElement;

  element.style.height = "auto";
  element.style.opacity = "";
}

function beforeLeave(el: Element): void {
  const element = el as HTMLElement;

  element.style.height = `${element.scrollHeight}px`;
  element.style.opacity = "1";

  /**
   * Force browser reflow.
   *
   * Zonder dit kan de browser de beginhoogte overslaan.
   * Dan ziet hij soms alleen:
   *
   * height: auto -> height: 0
   *
   * En dat kan een hakkelige of ontbrekende animatie geven.
   */
  void element.offsetHeight;
}

function leave(el: Element): void {
  const element = el as HTMLElement;

  requestAnimationFrame(() => {
    element.style.height = "0";
    element.style.opacity = "0";
  });
}

function afterLeave(el: Element): void {
  const element = el as HTMLElement;

  element.style.height = "";
  element.style.opacity = "";
}
</script>

<template>
  <div class="geo-info-toggle">
    <button
      :id="buttonId"
      class="geo-info-toggle__button"
      type="button"
      :aria-expanded="isOpen"
      :aria-controls="id"
      @click="toggle"
    >
      <span>{{ buttonLabel }}</span>

      <span class="geo-info-toggle__icon" aria-hidden="true">
        <span class="geo-info-toggle__icon-symbol">
          {{ isOpen ? "−" : "+" }}
        </span>
      </span>
    </button>

    <Transition
      name="geo-info-toggle"
      @before-enter="beforeEnter"
      @enter="enter"
      @after-enter="afterEnter"
      @before-leave="beforeLeave"
      @leave="leave"
      @after-leave="afterLeave"
    >
      <div v-show="isOpen" class="geo-info-toggle__panel">
        <div
          :id="id"
          class="geo-info-toggle__content"
          role="region"
          :aria-labelledby="buttonId"
        >
          <slot />
        </div>
      </div>
    </Transition>
  </div>
</template>