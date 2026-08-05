<script setup lang="ts">
import {
  computed,
  inject,
  reactive,
  ref,
  watch,
} from "vue";

import AdminButton from "../form/AdminButton.vue";
import AdminChoiceCard from "../form/AdminChoiceCard.vue";

import { adminBootstrapKey } from "../../types/admin";

import type { DashboardBookingDetail } from "../../types/bookingDetail";
import type {
  BookingCateringOption,
  BookingLunchChoice,
} from "../../types/bookingCatering";

import {
  BookingCateringApiError,
  updateDashboardBookingCatering,
} from "../../services/dashboardBookingCateringApi";

const props = defineProps<{
  bookingId: number;
  foodAndDrink: DashboardBookingDetail["foodAndDrink"];
}>();

const emit = defineEmits<{
  completed: [
    message: string,
    refresh: boolean,
    conflict: boolean,
  ];
}>();

const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const cateringCsrfToken = bootstrap.bookingCateringCsrfToken;

const remiseLunchOption = computed<BookingCateringOption>(() => {
  const option =
    props.foodAndDrink.options.lunch.remise_lunch;

  if (!option) {
    throw new Error("Remiselunchconfiguratie ontbreekt.");
  }

  return option;
});

const ownPicnicOption = computed<BookingCateringOption>(() => {
  const option =
    props.foodAndDrink.options.lunch.eigen_picknick;

  if (!option) {
    throw new Error("Picknickconfiguratie ontbreekt.");
  }

  return option;
});

const editing = ref(false);
const submitting = ref(false);
const conflictDetected = ref(false);
const conflictRefreshReady = ref(false);

const error = ref<string | null>(null);
const issues = ref<Record<string, string>>({});

const values = reactive({
  remiseBreak: 0,
  kazerneBreak: 0,
  fortgrachtBreak: 0,
  waterIce: 0,
  lemonade: 0,
  remiseLunch: 0,
});

const lunchChoice = ref<BookingLunchChoice | null>(null);

const lunchErrorId = "catering-lunch-error";

const snackDefinitions = computed(
  () =>
    [
      [
        "remiseBreak",
        props.foodAndDrink.options.snacks.remise_break,
      ],
      [
        "kazerneBreak",
        props.foodAndDrink.options.snacks.kazerne_break,
      ],
      [
        "fortgrachtBreak",
        props.foodAndDrink.options.snacks.fortgracht_break,
      ],
      [
        "waterIce",
        props.foodAndDrink.options.snacks.waterijsje,
      ],
      [
        "lemonade",
        props.foodAndDrink.options.snacks.glas_limonade,
      ],
    ] as Array<
      [
        keyof typeof values,
        BookingCateringOption,
      ]
    >,
);

const orderedSnacks = computed(() =>
  snackDefinitions.value.filter(
    ([key]) => props.foodAndDrink[key] > 0,
  ),
);

const preview = computed(() => {
  const snacksTotal = snackDefinitions.value.reduce(
    (sum, [key, option]) =>
      sum + values[key] * option.price,
    0,
  );

  const lunchTotal =
    lunchChoice.value === "remise_lunch"
      ? values.remiseLunch *
        remiseLunchOption.value.price
      : 0;

  return snacksTotal + lunchTotal;
});

watch(
  () => props.foodAndDrink,
  () => {
    if (!editing.value) {
      reset();
      return;
    }

    if (conflictDetected.value) {
      conflictRefreshReady.value = true;
    }
  },
  {
    deep: true,
  },
);

function reset(): void {
  const keys = [
    "remiseBreak",
    "kazerneBreak",
    "fortgrachtBreak",
    "waterIce",
    "lemonade",
    "remiseLunch",
  ] as const;

  for (const key of keys) {
    values[key] = props.foodAndDrink[key];
  }

  lunchChoice.value = [
    "remise_lunch",
    "eigen_picknick",
  ].includes(props.foodAndDrink.lunchChoice)
    ? (props.foodAndDrink.lunchChoice as BookingLunchChoice)
    : null;

  conflictDetected.value = false;
  conflictRefreshReady.value = false;
  issues.value = {};
  error.value = null;
}

function open(): void {
  reset();
  editing.value = true;
}

function cancel(): void {
  if (!submitting.value) {
    editing.value = false;
  }
}

function toggle(
  key: keyof typeof values,
  option: BookingCateringOption,
  event: Event,
): void {
  const checked = (
    event.target as HTMLInputElement
  ).checked;

  values[key] = checked
    ? values[key] || option.min
    : 0;
}

function choose(choice: BookingLunchChoice): void {
  lunchChoice.value = choice;

  if (choice === "eigen_picknick") {
    values.remiseLunch = 0;
    return;
  }

  if (values.remiseLunch === 0) {
    values.remiseLunch =
      remiseLunchOption.value.min;
  }
}

function onLunchChoiceChange(choice: string | null): void {
  if (choice === "remise_lunch" || choice === "eigen_picknick") {
    choose(choice);
  }
}

function numberInput(
  key: keyof typeof values,
  event: Event,
): void {
  const raw = (
    event.target as HTMLInputElement
  ).value;

  values[key] = /^\d+$/.test(raw)
    ? Number(raw)
    : 0;
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat("nl-NL", {
    style: "currency",
    currency: "EUR",
  }).format(value);
}

async function save(): Promise<void> {
  if (
    submitting.value ||
    conflictDetected.value ||
    !lunchChoice.value
  ) {
    return;
  }

  submitting.value = true;
  error.value = null;
  issues.value = {};

  try {
    const result =
      await updateDashboardBookingCatering(
        {
          bookingId: props.bookingId,

          expected: {
            remiseBreak:
              props.foodAndDrink.remiseBreak,
            kazerneBreak:
              props.foodAndDrink.kazerneBreak,
            fortgrachtBreak:
              props.foodAndDrink.fortgrachtBreak,
            waterIce:
              props.foodAndDrink.waterIce,
            lemonade:
              props.foodAndDrink.lemonade,
            remiseLunch:
              props.foodAndDrink.remiseLunch,
            ownPicnic:
              props.foodAndDrink.ownPicnic,
          },

          proposed: {
            ...values,
            lunchChoice: lunchChoice.value,
          },
        },
        cateringCsrfToken,
      );

    editing.value = false;

    emit(
      "completed",
      result.code === "NO_CATERING_CHANGE"
        ? "Er zijn geen wijzigingen om op te slaan."
        : "Eten en drinken zijn bijgewerkt.",
      result.code === "SUCCESS",
      false,
    );
  } catch (exception) {
    if (
      exception instanceof BookingCateringApiError &&
      exception.result
    ) {
      if (
        exception.result.code ===
        "CATERING_CONFLICT"
      ) {
        conflictDetected.value = true;
        conflictRefreshReady.value = false;

        error.value =
          "Een andere planner heeft de cateringgegevens gewijzigd. " +
          "Neem de actuele gegevens over en voer uw wijziging daarna opnieuw in.";

        emit(
          "completed",
          "Een andere planner heeft de cateringgegevens gewijzigd. " +
            "De actuele gegevens worden opnieuw geladen.",
          true,
          true,
        );

        return;
      }

      if (
        [
          "INVALID_CATERING_SELECTION",
          "INVALID_STORED_BOOKING",
        ].includes(exception.result.code)
      ) {
        for (const issue of exception.result.validationIssues) {
          issues.value[issue.field] =
            issue.description ||
            issue.title ||
            message(issue.code);
        }

        error.value =
          "Controleer de gemarkeerde cateringkeuzes.";

        return;
      }
    }

    error.value =
      "Eten en drinken konden niet worden gewijzigd.";
  } finally {
    submitting.value = false;
  }
}

function message(code: string): string {
  const messages: Record<string, string> = {
    MISSING_LUNCH_SELECTION:
      "Kies Remiselunch of Eigen picknick.",

    INVALID_REMISE_LUNCH_QUANTITY:
      "Het aantal remiselunches valt buiten de toegestane grenzen.",

    CATERING_OPTION_LIMIT_EXCEEDED:
      "Dit aantal is hoger dan toegestaan.",

    CONFLICTING_LUNCH_SELECTION:
      "De opgeslagen lunchgegevens spreken elkaar tegen.",
  };

  return (
    messages[code] ??
    "Deze cateringkeuze is ongeldig."
  );
}
</script>

<template>
  <section class="admin-card admin-catering-panel">
    <h2>Eten en drinken</h2>

    <!-- Overzichtsmodus -->
    <template v-if="!editing">
      <div class="admin-catering-overview">
        <div class="admin-catering-overview__grid">
          <!-- Snacks -->
          <section class="admin-catering-overview__group">
            <h3 class="admin-catering-overview__title">
              Snacks
            </h3>

            <dl
              v-if="orderedSnacks.length"
              class="admin-catering-overview__list"
            >
              <div
                v-for="[key, option] in orderedSnacks"
                :key="key"
                class="admin-catering-overview__row"
              >
                <dt class="admin-catering-overview__label">
                  {{ option.label }}
                </dt>

                <dd class="admin-catering-overview__value">
                  {{ foodAndDrink[key] }} stuks
                </dd>
              </div>
            </dl>

            <p
              v-else
              class="
                admin-catering-overview__empty
                admin-booking-detail__muted
              "
            >
              Geen extra snacks of drinken besteld.
            </p>
          </section>

          <!-- Lunch -->
          <section class="admin-catering-overview__group">
            <h3 class="admin-catering-overview__title">
              Lunch
            </h3>

            <dl
              v-if="
                foodAndDrink.lunchChoice ===
                  'remise_lunch' ||
                foodAndDrink.lunchChoice ===
                  'eigen_picknick'
              "
              class="admin-catering-overview__list"
            >
              <div
                v-if="
                  foodAndDrink.lunchChoice ===
                  'remise_lunch'
                "
                class="admin-catering-overview__row"
              >
                <dt class="admin-catering-overview__label">
                  {{ remiseLunchOption.label }}
                </dt>

                <dd class="admin-catering-overview__value">
                  {{ foodAndDrink.remiseLunch }} stuks
                </dd>
              </div>

              <div
                v-else
                class="admin-catering-overview__row"
              >
                <dt class="admin-catering-overview__label">
                  {{ ownPicnicOption.label }}
                </dt>

                <dd class="admin-catering-overview__value">
                  Geselecteerd
                </dd>
              </div>
            </dl>

            <p
              v-else-if="
                foodAndDrink.lunchChoice === 'conflict'
              "
              class="
                admin-catering-overview__status
                admin-catering-overview__status--error
              "
            >
              Remiselunch en Eigen picknick zijn beide
              opgeslagen.
            </p>

            <p
              v-else
              class="
                admin-catering-overview__status
                admin-booking-detail__muted
              "
            >
              Geen lunchkeuze vastgelegd.
            </p>
          </section>
        </div>

        <div class="admin-catering-overview__actions">
          <AdminButton @click="open">
            Wijzigen
          </AdminButton>
        </div>
      </div>
    </template>

    <!-- Bewerkmodus -->
    <form
      v-else
      class="admin-catering-form"
      @submit.prevent="save"
    >
      <fieldset :disabled="submitting">
        <legend>Snacks en drinken</legend>

        <div class="admin-catering-option-grid">
          <div
          v-for="[key, option] in snackDefinitions"
          :key="key"
          class="admin-catering-option"
        >
          <label
            class="admin-catering-option__card"
            :class="{
              'admin-catering-option--selected': values[key] > 0,
            }"
            :for="`catering-snack-${key}`"
          >
            <input
              :id="`catering-snack-${key}`"
              class="admin-catering-option__input"
              type="checkbox"
              :checked="values[key] > 0"
              :aria-describedby="issues[key] ? `catering-snack-${key}-error` : undefined"
              :aria-invalid="issues[key] ? 'true' : undefined"
              @change="toggle(key, option, $event)"
            >

            <span
              class="admin-catering-option__indicator"
              aria-hidden="true"
            />

            <span class="admin-catering-option__content">
            <strong>
              {{ option.label }}
            </strong>

          <span class="admin-catering-option__description">
            {{ option.description }}
            <span class="admin-catering-option__meta">
              {{ formatCurrency(option.price) }} per leerling
              ·
              {{ option.min }}–{{ option.max }} leerlingen
            </span>
            </span>
            </span>
          </label>

          <input
            v-if="values[key] > 0"
            :id="`catering-snack-${key}-quantity`"
            class="admin-catering-option__quantity"
            type="number"
            inputmode="numeric"
            step="1"
            :min="option.min"
            :max="option.max"
            :aria-label="`Aantal leerlingen voor ${option.label}`"
            :value="values[key]"
            @input="numberInput(key, $event)"
          >

          <p
            v-if="issues[key]"
            :id="`catering-snack-${key}-error`"
            class="
              admin-field__message
              admin-field__message--error
            "
          >
            {{ issues[key] }}
          </p>
          </div>
        </div>
      </fieldset>

      <fieldset :disabled="submitting">
        <legend>Lunch</legend>

        <div class="admin-catering-lunch-grid">
          <div class="admin-catering-lunch">
            <AdminChoiceCard
              :model-value="lunchChoice"
              value="remise_lunch"
              :label="remiseLunchOption.label"
              :description="remiseLunchOption.description"
              :meta="`${formatCurrency(remiseLunchOption.price)} per leerling · ${remiseLunchOption.min}–${remiseLunchOption.max} leerlingen`"
              name="catering-lunch"
              :error="Boolean(issues.lunchChoice || issues.remiseLunch)"
              :described-by="issues.lunchChoice || issues.remiseLunch ? lunchErrorId : undefined"
              @change="onLunchChoiceChange"
            />

            <input
              v-if="lunchChoice === 'remise_lunch'"
              id="catering-remise-lunch-quantity"
              class="admin-catering-lunch__quantity"
              type="number"
              inputmode="numeric"
              step="1"
              :min="remiseLunchOption.min"
              :max="remiseLunchOption.max"
              aria-label="Aantal remiselunches"
              :value="values.remiseLunch"
              @input="numberInput('remiseLunch', $event)"
            >
          </div>

          <AdminChoiceCard
            :model-value="lunchChoice"
            value="eigen_picknick"
            :label="ownPicnicOption.label"
            :description="ownPicnicOption.description"
            :meta="`${formatCurrency(ownPicnicOption.price)} per leerling`"
            name="catering-lunch"
            :error="Boolean(issues.lunchChoice || issues.remiseLunch)"
            :described-by="issues.lunchChoice || issues.remiseLunch ? lunchErrorId : undefined"
            @change="onLunchChoiceChange"
          />
        </div>

        <p
          v-if="
            issues.lunchChoice ||
            issues.remiseLunch
          "
          :id="lunchErrorId"
          class="
            admin-field__message
            admin-field__message--error
          "
        >
          {{
            issues.lunchChoice ||
            issues.remiseLunch
          }}
        </p>
      </fieldset>

      <div class="admin-catering-preview">
        Voorlopige cateringextra’s:

        <strong>
          {{ formatCurrency(preview) }}
        </strong>
      </div>

      <p
        v-if="error"
        class="
          admin-field__message
          admin-field__message--error
        "
        role="alert"
      >
        {{ error }}
      </p>

      <div
        v-if="conflictDetected"
        class="admin-catering-conflict"
      >
        <p>Actuele serverwaarden:</p>

        <dl class="admin-details">
          <template
            v-for="[key, option] in snackDefinitions"
            :key="key"
          >
            <dt>{{ option.label }}</dt>
            <dd>{{ foodAndDrink[key] }}</dd>
          </template>

          <dt>Lunch</dt>

          <dd
            v-if="
              foodAndDrink.lunchChoice ===
              'remise_lunch'
            "
          >
            {{ remiseLunchOption.label }}
            —
            {{ foodAndDrink.remiseLunch }} stuks
          </dd>

          <dd
            v-else-if="
              foodAndDrink.lunchChoice ===
              'eigen_picknick'
            "
          >
            {{ ownPicnicOption.label }}
          </dd>

          <dd
            v-else-if="
              foodAndDrink.lunchChoice ===
              'conflict'
            "
          >
            Conflict: Remiselunch en Eigen picknick
            zijn beide vastgelegd.
          </dd>

          <dd v-else>
            Geen lunchkeuze vastgelegd.
          </dd>
        </dl>

        <p>
          Neem deze gegevens over voordat u opnieuw
          opslaat.
        </p>

        <AdminButton
          variant="secondary"
          :disabled="
            submitting ||
            !conflictRefreshReady
          "
          @click="reset"
        >
          Actuele gegevens overnemen
        </AdminButton>
      </div>

      <div class="admin-catering-actions">
        <AdminButton
          variant="secondary"
          :disabled="submitting"
          @click="cancel"
        >
          Annuleren
        </AdminButton>

        <AdminButton
          type="submit"
          :loading="submitting"
          :disabled="
            conflictDetected ||
            !lunchChoice
          "
        >
          Opslaan
        </AdminButton>
      </div>
    </form>
  </section>
</template>
