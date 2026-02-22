<script setup lang="ts">
import { computed, toRef } from 'vue';
import { useFieldFlash, type ErrorBehavior } from "../../composables/useFieldFlash"
import { type ValidationShape } from '../../validation/booking';
import FieldFlash from './FieldFlash.vue';

type Model = string

const props = withDefaults(
    defineProps<{
    id: string;
    label: string;
    modelValue: Model; //v-model support
    issue?: ValidationShape
    type?: string;
    placeholder?: string;
    required?: boolean;

    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;

    flashTrigger: number;
}>(),
    {
        type: "text",
        required: true,
        errorBehavior: "auto",
        autoDismissMs: 3000,
        issue: () => ({}) //function default object props
    }
);

const issueRef = toRef(props, "issue");
const triggerRef = toRef(props, "flashTrigger");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs")



const emit = defineEmits<{
    (e: "update:modelValue", value: Model): void;
    (e: "blur"): void
}>();


const { visible, msg } = useFieldFlash({
    issue: issueRef,
    trigger: triggerRef,
    behavior: behaviorRef,
    autoDismissMs: dismissRef

});

const hasError = computed(() => !!props.issue?.error);
const hasWarning = computed(() => !props.issue?.error && !!props.issue?.warning);

function onInput(e: Event): void {
    emit("update:modelValue", (e.target as HTMLInputElement).value)
}







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
    <div class="field">
        <FieldFlash
            :visible="visible"
            :hasError="hasError"
            :hasWarning="hasWarning"
            :id="`${id}-error`"
            :msg="msg"
        />
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
            :class="{'has-error' : ( hasError && (modelValue?.length ?? 0 > 0))}"

            @input="onInput"
            @blur="emit('blur')"
            
            :aria-invalid="hasError ? 'true' : 'false'"
            :aria-describedby="hasError ? `${id}-error` : undefined"
            
        >
    </div> 
</template>

<style scoped>
    .field {
        width: 100%;
    }
    /* 2. De Input Styling */
    .form-input {
        width: 100%;
        background-color: white;
        transition: border-color 0.3s, background-color 0.3s;
        line-height: var(--input-line-height);
    }

    .form-input.has-error {
        border-color: var(--flash-error-input-border);
        background-color: var(--flash-error-input-bg);
    }

    .input-label {
        padding-bottom: 0.5rem;
    }

</style>