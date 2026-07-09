<script setup lang="ts">
import { computed, toRef, ref } from 'vue';
import { useFieldFlash, type ErrorBehavior } from "../../composables/useFieldFlash"
import type { ValidationShape } from '../../types/validation/FieldErrorTypes';
import FieldFlash from './FieldFlash.vue';
import type { InputMode } from '../../types/booking/BookingFieldTypes';

type Model = string

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    type?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
    autocomplete?: string;
    issue?: ValidationShape;
    flashTrigger: number;
    modelValue: Model;
    inputmode?: InputMode;
  }>(),
  {
    type: "text",
    required: true,
    disabled: false,
    errorBehavior: "auto",
    autoDismissMs: 3000,
    autocomplete: undefined,
    inputmode: undefined,
    issue: () => ({}),
  },
);

const emit = defineEmits<{
    (e: "update:modelValue", value: Model): void;
    (e: "blur"): void
}>();


const issueRef = toRef(props, "issue");
const triggerRef = toRef(props, "flashTrigger");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs")


const { visible, msg } = useFieldFlash({
    issue: issueRef,
    trigger: triggerRef,
    behavior: behaviorRef,
    autoDismissMs: dismissRef

});

const hasError = computed(() => !!props.issue?.error);
const hasWarning = computed(() => !props.issue?.error && !!props.issue?.warning);
const hasValue = computed(() => props.modelValue.trim().length > 0);
const inputRef = ref<HTMLInputElement | null>(null);

function onInput(e: Event): void {
    emit("update:modelValue", (e.target as HTMLInputElement).value)
}

const focus = () => { inputRef.value?.focus() };

defineExpose({ focus });



/**
 * ============================================================
 *  HOE EEN SFC IMPORT + defineExpose SAMENWERKEN
 * ============================================================
 *
 *  STAP 1 — WAT IMPORTEER JE MET `import GeoFormInputField`?
 * ------------------------------------------------------------
 *  Je importeert de COMPONENT DEFINITIE — niet een instantie.
 *  Vergelijk het met een blauwdruk of een klasse:
 *
 *      import GeoFormInputField from './GeoFormInputField.vue'
 *      //  ^^ Dit is als: import { MyClass } from './MyClass.ts'
 *      //  Je hebt de klasse, maar nog geen object (instantie)
 *
 *  Vue gebruikt deze definitie om instanties te maken zodra
 *  het component in de DOM wordt gerenderd via <template>.
 *
 *
 *  STAP 2 — WAT IS EEN INSTANTIE?
 * ------------------------------------------------------------
 *  Elke keer dat Vue `<GeoFormInputField />` in de template
 *  tegenkomt, maakt het een NIEUWE instantie aan. Zo'n
 *  instantie heeft zijn eigen:
 *    - reactieve state (ref, computed, etc.)
 *    - eigen DOM-elementen
 *    - eigen lifecycle (onMounted, onUnmounted, etc.)
 *
 *
 *  STAP 3 — WAT DOET `ref="fieldRefs.schoolnaam"` IN DE TEMPLATE?
 * ------------------------------------------------------------
 *  Vue slaat de instantie op in de ref zodra het component
 *  gemount is. Daarna kun je vanuit de parent doen:
 *
 *      fieldRefs.value.schoolnaam  // → de live instantie
 *
 *  Maar: wat zit er IN die instantie? Dat bepaalt defineExpose.
 *
 *
 *  STAP 4 — WAAROM defineExpose?
 * ------------------------------------------------------------
 *  In Vue 3 met <script setup> zijn ALLE variabelen en functies
 *  standaard PRIVÉ. De parent kan er niet bij.
 *
 *  Met `defineExpose({ focus })` zeg je expliciet:
 *  "Alleen `focus` mag zichtbaar zijn voor de parent via ref."
 *
 *  Zonder defineExpose:
 *      fieldRefs.value.schoolnaam.focus()  // ❌ undefined
 *
 *  Met defineExpose({ focus }):
 *      fieldRefs.value.schoolnaam.focus()  // ✅ werkt
 *
 *
 *  STAP 5 — typeof GeoFormInputField vs InstanceType<...>
 * ------------------------------------------------------------
 *  `typeof GeoFormInputField`
 *      → het TYPE van de component DEFINITIE (de blauwdruk)
 *
 *  `InstanceType<typeof GeoFormInputField>`
 *      → het TYPE van een instantie (het gecreëerde object)
 *      → dit bevat alleen wat defineExpose publiek maakt
 *
 *  Daarom gebruik je InstanceType in fieldRefs:
 *
 *      useTemplateRef<
 *        Record<BookingField, InstanceType<typeof GeoFormInputField>>
 *      >("fieldRefs")
 *
 *      // TypeScript weet nu dat .schoolnaam een .focus() heeft
 *      // want GeoFormInputField exposed die method
 *
 * ============================================================
 */


/**
 * defineProps registrates the public API from you component
 * It defines which props the parent may pass throught the child
 * Binding happens with uses :id = id
 * 
 */

/**
 * de ? is here undefined
 * So if the error is not there then undefined else string or null
 * 
 */

 /**
 * the update modelValue is explicite VUE syntax that is use in the V-model
 * the emit is needed, cuz the parent only listens to the child
 * 
 * 
 */

 /**
 * defineEmits declarates which events the child component fires to the parent
 * 
 */

 /**
  * within template props.id is also avvailable trought id 
  * Outside you need to declare with props.id
  */
</script>

<template>
  <div
    class="field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning && !hasError,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError && !hasWarning,
      'is-disabled': disabled,
    }"
  >
    <label :for="id" class="input-label">
      <span class="input-label__icon" aria-hidden="true">✎</span>
      <span>{{ label }}</span>
      <span v-if="required" class="input-label__required" aria-hidden="true">
        *
      </span>
    </label>

    <div class="fieldflash-shell-wrapper">
      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="hasWarning"
        :id="id"
        :msg="msg"
        :behavior="errorBehavior"
      />

      <input
        :id="id"
        ref="inputRef"
        class="form-input"
        :class="{
          'has-error': hasError,
          'has-warning': hasWarning && !hasError,
          'has-value': hasValue,
        }"
        :type="type || 'text'"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"
        :disabled="disabled"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-invalid="hasError ? 'true' : 'false'"
        :aria-describedby="hasError || hasWarning ? `${id}-issue` : undefined"
        @input="onInput"
        @blur="emit('blur')"
      />
    </div>
  </div>
</template>