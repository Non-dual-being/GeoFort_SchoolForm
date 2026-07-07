import { computed, ref, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";
import type {
  BookingProgramConfigData,
  FoodAndDrinkOption,
  LunchKey,
  SnackKey,
} from "../types/booking/BookingProgramConfigTypes";

type UseFoodAndDrinkSelectionParams = {
  formValues: Ref<BookingFormValues>;
  bookingProgramConfig: Ref<BookingProgramConfigData | null>;
  canShowFoodAndDrinkSelectionBase: ComputedRef<boolean>;
};

export type SnackFormField =
  | "remiseBreak"
  | "kazerneBreak"
  | "fortgrachtBreak"
  | "waterijsje"
  | "glasLimonade";

export type LunchFormField = "lunchChoice" | "remiseLunch";

export type FoodAndDrinkFormField = SnackFormField | LunchFormField;

const snackFieldByKey: Record<SnackKey, SnackFormField> = {
  remise_break: "remiseBreak",
  kazerne_break: "kazerneBreak",
  fortgracht_break: "fortgrachtBreak",
  waterijsje: "waterijsje",
  glas_limonade: "glasLimonade",
};

function parseInteger(value: string): number | null {
  const trimmed = value.trim();

  if (trimmed === "" || !/^[0-9]+$/.test(trimmed)) {
    return null;
  }

  const parsed = Number(trimmed);

  return Number.isInteger(parsed) ? parsed : null;
}

const euroFormatter = new Intl.NumberFormat("nl-NL", {
  style: "currency",
  currency: "EUR",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export function useFoodAndDrinkSelection(params: UseFoodAndDrinkSelectionParams) {
  const foodAndDrinkFlashTrigger = ref(0);

  const canShowFoodAndDrinkSelection = computed(() => {
    return (
      params.canShowFoodAndDrinkSelectionBase.value &&
      params.bookingProgramConfig.value !== null
    );
  });

  const snackOptions = computed(() => {
    const options = params.bookingProgramConfig.value?.foodAndDrinkOptions.snacks;

    if (!options) {
      return [];
    }

    return Object.values(options).map((option) => ({
      ...option,
      field: snackFieldByKey[option.key],
    }));
  });

  const lunchOptions = computed(() => {
    const options = params.bookingProgramConfig.value?.foodAndDrinkOptions.lunch;

    return options ? Object.values(options) : [];
  });

  const snackIssues = computed<Partial<Record<SnackFormField, string>>>(() => {
    if (!canShowFoodAndDrinkSelection.value) {
      return {};
    }

    const issues: Partial<Record<SnackFormField, string>> = {};

    for (const option of snackOptions.value) {
      const rawValue = params.formValues.value[option.field].trim();
      const value = parseInteger(rawValue);

      if (rawValue === "") {
        issues[option.field] = `Vul een geldig aantal in voor ${option.label}.`;
        continue;
      }

      if (value === null) {
        issues[option.field] = `Vul een geldig aantal in voor ${option.label}.`;
        continue;
      }

      if (value === 0) {
        continue;
      }

      if (value < option.min) {
        issues[option.field] = `Vul een geldig aantal in voor ${option.label}.`;
        continue;
      }

      if (value > option.max) {
        issues[option.field] = `Voor ${option.label} kunt u maximaal ${option.max} stuks opgeven.`;
      }
    }

    return issues;
  });

  const lunchIssue = computed<string | null>(() => {
    if (!canShowFoodAndDrinkSelection.value) {
      return null;
    }

    const lunchChoice = params.formValues.value.lunchChoice;

    if (lunchChoice === "") {
      return "Kies of u een remiselunch wilt bestellen of eigen lunch meeneemt.";
    }

    if (lunchChoice === "eigen_picknick") {
      return null;
    }

    const remiseLunch = params.bookingProgramConfig.value
      ?.foodAndDrinkOptions.lunch.remise_lunch;
    const rawValue = params.formValues.value.remiseLunch.trim();
    const value = parseInteger(rawValue);

    if (!remiseLunch || value === null) {
      return "Vul een geldig aantal in voor Remiselunch.";
    }

    if (value < remiseLunch.min) {
      return "Voor de remiselunch geldt een minimum van 50 stuks.";
    }

    if (value > remiseLunch.max) {
      return "Voor de remiselunch kunt u maximaal 200 stuks opgeven.";
    }

    return null;
  });

  const foodAndDrinkIssue = computed<string | null>(() => {
    const firstSnackIssue = Object.values(snackIssues.value)[0];

    return firstSnackIssue ?? lunchIssue.value;
  });

  const hasValidFoodAndDrinkSelection = computed(() => {
    return (
      canShowFoodAndDrinkSelection.value &&
      foodAndDrinkIssue.value === null
    );
  });

  function formatCurrency(price: number): string {
    return price === 0 ? "Gratis" : euroFormatter.format(price);
  }

  function resetFoodAndDrinkSelection(): void {
    params.formValues.value.remiseBreak = "0";
    params.formValues.value.kazerneBreak = "0";
    params.formValues.value.fortgrachtBreak = "0";
    params.formValues.value.waterijsje = "0";
    params.formValues.value.glasLimonade = "0";
    params.formValues.value.lunchChoice = "eigen_picknick";
    params.formValues.value.remiseLunch = "0";
    foodAndDrinkFlashTrigger.value = 0;
  }

  function normalizeFoodAndDrinkSelection(): void {
    for (const option of snackOptions.value) {
      const value = parseInteger(params.formValues.value[option.field]);
      params.formValues.value[option.field] =
        value === null || value <= 0 ? "0" : String(value);
    }

    if (params.formValues.value.lunchChoice === "eigen_picknick") {
      params.formValues.value.remiseLunch = "0";
      return;
    }

    if (params.formValues.value.lunchChoice !== "remise_lunch") {
      params.formValues.value.remiseLunch = "0";
      return;
    }

    const remiseLunch = parseInteger(params.formValues.value.remiseLunch);
    params.formValues.value.remiseLunch =
      remiseLunch === null ? "" : String(remiseLunch);
  }

  function getSnackIssue(field: SnackFormField): string | null {
    return snackIssues.value[field] ?? null;
  }

  function getLunchOption(key: LunchKey): FoodAndDrinkOption | null {
    return params.bookingProgramConfig.value?.foodAndDrinkOptions.lunch[key] ?? null;
  }

  return {
    canShowFoodAndDrinkSelection,
    hasValidFoodAndDrinkSelection,
    foodAndDrinkIssue,
    foodAndDrinkFlashTrigger,
    snackOptions,
    lunchOptions,
    lunchIssue,
    formatCurrency,
    resetFoodAndDrinkSelection,
    normalizeFoodAndDrinkSelection,
    getSnackIssue,
    getLunchOption,
  };
}
