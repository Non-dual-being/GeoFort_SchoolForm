<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  toRef,
  watch,
} from "vue";
import flatpickr from "flatpickr";
import { Dutch } from "flatpickr/dist/l10n/nl.js";
import type { Instance as FlatpickrInstance } from "flatpickr/dist/types/instance";
import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import {
  useFieldFlash,
  type ErrorBehavior,
} from "../../composables/useFieldFlash";
import { fetchDisabledDates } from "../../services/bookingDisabledDatesApi";
import FieldFlash from "./FieldFlash.vue";
import { 
  createAgendaDayInfo, 
  toIsoDate,
  setCalendarInfo,
  getAgendaVisualKind,
  getAgendaInfoTitle,
  getAgendaInfoDescription,
  CalendarInfoDiv
} from "../../config/booking/calendar/helpers";
import { AgendaAvailabilityDetail, DisabledDateDetail } from "../../types/booking/BookingDateType";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: string;
    required?: boolean;
    issue?: ValidationShape;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
    flashTrigger: number;
    autocomplete?: string;
    disabled?: boolean;
  }>(),
  {
    disabled: false,
    errorBehavior: "persistent",
    autoDismissMs: 3000,
    issue: () => ({}),
  },
);

const emit = defineEmits<{
  "update:modelValue": [value: string];
  blur: [];
  loaded: [];
  error: [message: string];
}>();

/**
 * define emits is the possibility for the child to emit events to the parent
 * 
 */
let calendarInfoEl: CalendarInfoDiv = null;

const inputRef = ref<HTMLInputElement | null>(null);
const fp = ref<FlatpickrInstance | null>(null);

const isLoading = ref(true);
const localError = ref("");
const localFlashTrigger = ref(0);

const issueRef = toRef(props, "issue");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs");

const effectiveIssue = computed<ValidationShape>(() => {
  if (localError.value) {
    return {
      error: localError.value,
    };
  }

  return issueRef.value ?? {};
});

const effectiveFlashTrigger = computed(() => {
  return props.flashTrigger + localFlashTrigger.value;
});

const { visible, msg } = useFieldFlash({
  issue: effectiveIssue,
  trigger: effectiveFlashTrigger,
  behavior: behaviorRef,
  autoDismissMs: dismissRef,
});


const hasError = computed(() => {
  return Boolean(effectiveIssue.value.error);
});

const hasWarning = computed(() => {
  return !hasError.value && Boolean(effectiveIssue.value.warning);
});

const describedBy = computed(() => {
  if (hasError.value || hasWarning.value) {
    return `${props.id}-issue`;
  }

  return undefined;
});

const inputDisabled = computed(() => {
  return props.disabled || isLoading.value;
});

const hasValue = computed(() => props.modelValue.trim().length > 0);

const isValid = computed(() => {
  return hasValue.value && !hasError.value && !hasWarning.value;
});

defineExpose({
  focus,
});

function focus(): void {
  if (fp.value?.altInput) {
    fp.value.altInput.focus();
    return;
  }

  inputRef.value?.focus();
}

function syncAltInputStateClasses(): void {
  const altInput = fp.value?.altInput;

  if (!altInput) {
    return;
  }

  altInput.classList.toggle("has-error", hasError.value);
  altInput.classList.toggle("has-warning", hasWarning.value && !hasError.value);
  altInput.classList.toggle("has-value", hasValue.value);
  altInput.classList.toggle("is-valid", isValid.value);
  altInput.classList.toggle("is-disabled", inputDisabled.value);

  altInput.setAttribute("aria-invalid", hasError.value ? "true" : "false");

  if (describedBy.value) {
    altInput.setAttribute("aria-describedby", describedBy.value);
  } else {
    altInput.removeAttribute("aria-describedby");
  }
}

watch(
  [hasError, hasWarning, hasValue, isValid, inputDisabled, describedBy],
  () => {
    syncAltInputStateClasses();
  },
  {
    flush: "post",
  },
);

let loadedSuccessfully = false;

onMounted(async () => {
  try {
    const result = await fetchDisabledDates();

    //optimalisation to search for a blocked date
    const disabledDatesSet = new Set(result.disabledDates);
  
    //key value store (faster then array)
    const disabledDetailByDate = new Map<string, DisabledDateDetail>(
      result.details.map((detail) => [detail.datum, detail]),
    );

    const availabilityByDate = new Map<string, AgendaAvailabilityDetail>(
      (result.availabilityDetails ?? []).map((detail) => [
          detail.datum,
          detail
      ])
    );

    /**je kan typeren als de AgendaAvailabliltyDetail om het ook een lege araat mag zijn */

    await nextTick();

    if (!inputRef.value) {
      return;
    }

    fp.value = flatpickr(inputRef.value, {
      locale: Dutch,
      enableTime: false,
      dateFormat: "Y-m-d",

      altInput: true,
      altFormat: "l d F Y",
      altInputClass: "form-input geo-date-field__input geo-date-field__input--alt",

      allowInput: false,
      clickOpens: !props.disabled,
      disableMobile: true,

      minDate: result.minDate,
      maxDate: result.maxDate,

      disable: [
        (date: Date): boolean => {
          const isoDate = toIsoDate(date);

          const info = createAgendaDayInfo({
            date: isoDate,
            dateObj: date,
            disabledByDateList: disabledDatesSet.has(isoDate),
            disabledDetail: disabledDetailByDate.get(isoDate),
            availabilityDetail: availabilityByDate.get(isoDate)

          });

          return info.status === "not_bookable"
        }
      ],

      defaultDate: props.modelValue || undefined,

      onReady: (_selectedDates, _dateStr, instance) => {
          calendarInfoEl = document.createElement("div");
          calendarInfoEl.className = "geo-flatpickr-info";
          calendarInfoEl.dataset.kind = "default";

          instance.calendarContainer.appendChild(calendarInfoEl);
          setCalendarInfo(
            calendarInfoEl,
            null
          );

      },

      onOpen: () => {
          setCalendarInfo(calendarInfoEl, null);
      },

      onMonthChange: () => {
        setCalendarInfo(calendarInfoEl, null);
      },

      onYearChange: () => {
        setCalendarInfo(calendarInfoEl, null);
      },

      onChange: (_selectedDates, dateStr) => {
        localError.value = "";
        emit("update:modelValue", dateStr);
      },

      onClose: async (_selectedDates, dateStr) => {
          emit("update:modelValue", dateStr);

          await nextTick();

          emit("blur");
      },

      onDayCreate: (_dObj, _dStr, _fp, dayElem) => {
        const date = toIsoDate(dayElem.dateObj);

        const info = createAgendaDayInfo({
          date,
          dateObj: dayElem.dateObj,
          disabledByDateList: disabledDatesSet.has(date),
          disabledDetail: disabledDetailByDate.get(date),
          availabilityDetail: availabilityByDate.get(date),
        });

        const visualKind = getAgendaVisualKind(info);

        dayElem.classList.add("geo-agenda-day");
        dayElem.dataset.geoAgendaKind = visualKind;

        if (info.status === "bookable") {
          dayElem.classList.add("geo-bookable-date");
          dayElem.dataset.availableStudents = String(info.availableStudents);

          if (visualKind === "bookable_full") {
            dayElem.classList.add("geo-bookable-full");
          }

          if (visualKind === "bookable_limited") {
            dayElem.classList.add("geo-bookable-limited");
          }
        }

        if (info.status === "not_bookable") {
          dayElem.classList.add("geo-not-bookable-date");

          if (info.reason === "fully_booked") {
            dayElem.classList.add("geo-fully-booked");
          }

          if (info.reason === "manual") {
            dayElem.classList.add("geo-disabled-date", "geo-disabled-manual");
          }

          if (info.reason === "school_vacation") {
            dayElem.classList.add(
              "geo-disabled-date",
              "geo-disabled-school-vacation",
            );
          }

          if (info.reason === "weekend") {
            dayElem.classList.add("geo-disabled-date", "geo-disabled-weekend");
          }
        }

        dayElem.title = `${getAgendaInfoTitle(info)} - ${getAgendaInfoDescription(
          info,
        )}`;

        dayElem.addEventListener("mouseenter", () => {
          setCalendarInfo(calendarInfoEl, info);
        });

        dayElem.addEventListener("focus", () => {
          setCalendarInfo(calendarInfoEl, info);
        });

        dayElem.addEventListener("mouseleave", () => {
          setCalendarInfo(calendarInfoEl, null);
        });

        dayElem.addEventListener("blur", () => {
          setCalendarInfo(calendarInfoEl, null);
        });
      },
    });

    applyDisabledState();
    syncAltInputStateClasses();
        loadedSuccessfully = true;
  } catch (error) {
    const message =
      error instanceof Error
        ? error.message
        : "Kalender kon niet worden geladen.";

    localError.value = message;
    localFlashTrigger.value++;

    emit("error", message);
  } finally {
    isLoading.value = false;
    applyDisabledState();
    syncAltInputStateClasses();
        if (loadedSuccessfully) {
      emit("loaded");
    }
  }
});

watch(
  () => props.modelValue,
  (value) => {
    if (!fp.value) {
      return;
    }

    if (!value) {
      fp.value.clear();
      return;
    }

    if (value !== fp.value.input.value) {
      fp.value.setDate(value, false, "Y-m-d");
    }
  },
);

watch(
  () => props.disabled,
  () => {
    applyDisabledState();
  },
);

onBeforeUnmount(() => {
  fp.value?.destroy();
  fp.value = null;
  calendarInfoEl = null;
});

function applyDisabledState(): void {
  if (!fp.value) {
    if (inputRef.value) {
      inputRef.value.disabled = inputDisabled.value;
      //disables the input if flatpickr still loading
    }

    return;
  }

  fp.value.set("clickOpens", !inputDisabled.value); //if fp is disabled dont open 

  fp.value.input.disabled = inputDisabled.value; //disabled second input with lang date if orginele input is disabled

  if (fp.value.altInput) {
    fp.value.altInput.disabled = inputDisabled.value;
  }
}

/**
 * We zetten de disabled dates om naar een Set.
 *
 * result.data.disabledDates is oorspronkelijk een array, bijvoorbeeld:
 * ["2026-06-01", "2026-06-02", "2026-06-03"]
 *
 * Met een array zouden we per kalenderdag moeten zoeken met:
 * disabledDates.includes(date)
 *
 * Dat werkt, maar bij veel datums zoekt JavaScript steeds opnieuw door de array.
 *
 * Een Set is bedoeld voor snelle "bestaat deze waarde?" checks.
 * Daardoor kunnen we later simpel en snel vragen:
 *
 * disabledDatesSet.has(date)
 *
 * In dit component betekent dat:
 * "Is deze kalenderdag geblokkeerd?"
 */


/**
 * We zetten de detail-array om naar een Map.
 *
 * result.data.details is oorspronkelijk een array met objecten, bijvoorbeeld:
 *
 * [
 *   {
 *     datum: "2026-06-01",
 *     type: "school_vacation",
 *     reden: "Zomervakantie"
 *   }
 * ]
 *
 * Met een array zouden we per kalenderdag moeten zoeken met:
 * details.find((item) => item.datum === date)
 *
 * Een Map werkt als key-value opslag.
 * De key is hier de datum.
 * De value is het volledige detail-object.
 *
 * Daardoor kunnen we later direct doen:
 *
 * detailByDate.get(date)
 *
 * In dit component betekent dat:
 * "Geef mij de reden/type/details die bij deze geblokkeerde datum horen."
 */

 /**
 * Synchroniseert de disabled-status van het date field.
 *
 * Dit component heeft twee situaties:
 *
 * 1. Flatpickr bestaat nog niet.
 *    Dan hebben we alleen de gewone HTML input via inputRef.
 *
 * 2. Flatpickr bestaat wel.
 *    Dan heeft Flatpickr naast de originele input vaak ook een altInput.
 *
 * Omdat we altInput: true gebruiken, maakt Flatpickr een extra zichtbare input.
 *
 * De originele input bevat de technische waarde:
 * bijvoorbeeld "2026-06-01"
 *
 * De altInput toont de mooie gebruikerswaarde:
 * bijvoorbeeld "maandag 01 juni 2026"
 *
 * Daarom moeten we bij disabled-state beide inputs meenemen:
 * - de originele Flatpickr input
 * - de zichtbare altInput
 *
 * Daarnaast zetten we clickOpens uit als het veld disabled is.
 * Anders zou de input misschien niet bewerkbaar zijn, maar de kalender
 * nog wel kunnen openen.
 */

  /**
   * Als Flatpickr nog niet geïnitialiseerd is, bestaat fp.value nog niet.
   * We kunnen dan alleen de gewone HTML input disablen/enablen.
   */

  /**
   * Bepaalt of klikken op de input de kalender opent.
   *
   * Als inputDisabled true is:
   * clickOpens wordt false.
   *
   * Als inputDisabled false is:
   * clickOpens wordt true.
   */


  /**
   * Disabled/enabled de originele input waar Flatpickr op gestart is.
   */

  /**
   * Disabled/enabled de zichtbare altInput van Flatpickr.
   *
   * Deze bestaat alleen als altInput: true gebruikt wordt.
   */

/**
 * Het date field is disabled als:
 *
 * - de parent disabled=true doorgeeft;
 * - of de kalenderdata nog aan het laden is.
 *
 * Tijdens het laden willen we voorkomen dat de gebruiker al een datum kiest
 * voordat de disabled dates uit de API bekend zijn.

/**
 * Events die dit child component naar de parent mag sturen.
 *
 * update:modelValue:
 * Wordt gebruikt door Vue v-model.
 * Als de gebruiker een datum kiest, sturen we de nieuwe waarde naar de parent.
 *
 * blur:
 * Wordt gebruikt om de parent te laten weten dat de gebruiker klaar is
 * met dit veld. Bij Flatpickr gebruiken we hiervoor meestal onClose,
 * omdat de gebruiker met een kalender werkt in plaats van een normale input.
 *
 * loaded:
 * Wordt verstuurd zodra Flatpickr klaar is met initialiseren.
 * De parent kan dit gebruiken als hij wil weten wanneer de kalender beschikbaar is.
 *
 * error:
 * Wordt verstuurd als de kalenderdata niet geladen kan worden of als er
 * lokaal in dit component iets misgaat.
 */

 /**
 * Maakt functies van dit child component beschikbaar voor de parent.
 *
 * In <script setup> zijn functies standaard privé binnen het component.
 * De parent kan dus niet automatisch child.focus() aanroepen.
 *
 * Omdat de parent bij validatiefouten het eerste foutieve veld wil focussen,
 * moet dit component een publieke focus-functie aanbieden.
 *
 * De parent bewaart component refs in formFieldRefs en kan daarna doen:
 *
 * formFieldRefs.value[field]?.focus()
 *
 * Zonder defineExpose({ focus }) zou die focus-functie niet beschikbaar zijn
 * voor de parent.
 */

</script>

<template>
  <div
    class="field geo-date-field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning && !hasError,
      'has-value': hasValue,
      'is-valid': isValid,
      'is-disabled': inputDisabled,
    }"
  >
    <label class="input-label" :for="id">
      <span class="input-label__icon" aria-hidden="true">📅</span>
      <span>{{ label }}</span>
      <span v-if="required" class="input-label__required" aria-hidden="true">
        *
      </span>
    </label>

    <div class="fieldflash-shell-wrapper">
      <input
        :id="id"
        ref="inputRef"
        class="form-input geo-date-field__input"
        :class="{
          'has-error': hasError,
          'has-warning': hasWarning && !hasError,
          'has-value': hasValue,
          'is-valid': isValid,
        }"
        type="text"
        :value="modelValue"
        :required="required"
        :disabled="inputDisabled"
        :autocomplete="autocomplete ?? 'off'"
        :aria-invalid="hasError ? 'true' : 'false'"
        :aria-describedby="describedBy"
      />

      <p v-if="isLoading" class="geo-date-field__help">
        Kalender wordt geladen...
      </p>

      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="hasWarning"
        :id="id"
        :msg="msg"
        :behavior="errorBehavior"
      />
    </div>
  </div>
</template>

