<script setup lang="ts">
import type {
  FoodAndDrinkInfoConfig,
  FoodAndDrinkInfoItem,
  LunchKey,
  PricesConfig,
  SnackKey,
} from "../../types/booking/BookingProgramConfigTypes";

const props = defineProps<{
  id: string;
  label: string;
  info: FoodAndDrinkInfoConfig;
  prices: PricesConfig;
}>();

const euroFormatter = new Intl.NumberFormat("nl-NL", {
  style: "currency",
  currency: "EUR",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

function formatPrice(price: number): string {
  return price === 0 ? "Gratis" : euroFormatter.format(price);
}

function getSnackPrice(item: FoodAndDrinkInfoItem): string | null {
  if (!item.key || !(item.key in props.prices.snacks)) {
    return null;
  }

  return formatPrice(props.prices.snacks[item.key as SnackKey]);
}

function getLunchPrice(item: FoodAndDrinkInfoItem): string | null {
  if (!item.key || !(item.key in props.prices.lunch)) {
    return null;
  }

  return formatPrice(props.prices.lunch[item.key as LunchKey]);
}
</script>

<template>
  <section
    class="food-drink-info-card"
    :id="id"
    :aria-label="label"
  >
    <div class="food-drink-info-card__layout">
      <section class="food-drink-info-section">
        <header class="food-drink-info-section__header">
          <h3 class="food-drink-info-section__title">
            Inbegrepen bij het arrangement
          </h3>
        </header>

        <div class="food-drink-info-section__cards">
          <article class="food-drink-info-block">
            <ul class="food-drink-info-list food-drink-info-list--included">
              <li
                v-for="item in info.included"
                :key="item.label"
                class="food-drink-info-row food-drink-info-row--included"
              >
                <span class="food-drink-info-pill">
                  {{ item.label }}
                </span>

                <span class="food-drink-info-row__description">
                  {{ item.description }}
                </span>
              </li>
            </ul>
          </article>
        </div>
      </section>

      <section class="food-drink-info-section">
        <header class="food-drink-info-section__header">
          <h3 class="food-drink-info-section__title">
            Optioneel bij te boeken
          </h3>
        </header>

        <div class="food-drink-info-section__cards food-drink-info-section__cards--options">
          <article class="food-drink-info-block">
            <h4 class="food-drink-info-block__title">
              Snacks
            </h4>

            <ul class="food-drink-info-list">
              <li
                v-for="item in info.optional.snacks"
                :key="item.key ?? item.label"
                class="food-drink-info-row food-drink-info-row--priced"
              >
                <span class="food-drink-info-pill">
                  {{ item.label }}
                </span>

                <span class="food-drink-info-row__body">
                  <span class="food-drink-info-row__description">
                    {{ item.description }}
                  </span>

                  <span
                    v-if="getSnackPrice(item)"
                    class="food-drink-info-price"
                  >
                    {{ getSnackPrice(item) }}
                  </span>
                </span>
              </li>
            </ul>
          </article>

          <article class="food-drink-info-block">
            <h4 class="food-drink-info-block__title">
              Lunch
            </h4>

            <ul class="food-drink-info-list">
              <li
                v-for="item in info.optional.lunch"
                :key="item.key ?? item.label"
                class="food-drink-info-row food-drink-info-row--priced"
              >
                <span class="food-drink-info-pill">
                  {{ item.label }}
                </span>

                <span class="food-drink-info-row__body">
                  <span class="food-drink-info-row__description">
                    {{ item.description }}
                  </span>

                  <span
                    v-if="getLunchPrice(item)"
                    class="food-drink-info-price"
                  >
                    {{ getLunchPrice(item) }}
                  </span>
                </span>
              </li>
            </ul>
          </article>
        </div>
      </section>

      <section class="food-drink-info-section">
        <header class="food-drink-info-section__header">
          <h3 class="food-drink-info-section__title">
            Extra informatie
          </h3>
        </header>

        <div class="food-drink-info-section__cards">
          <article class="food-drink-info-block">
            <ul class="food-drink-info-notes">
              <li
                v-for="note in info.notes"
                :key="note"
              >
                {{ note }}
              </li>
            </ul>
          </article>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>
.food-drink-info-card {
  width: 100%;
  color: var(--color-font-main);
}

.food-drink-info-card__layout {
  display: grid;
  gap: 1.35rem;
}

.food-drink-info-section {
  display: grid;
  gap: 0.85rem;
  min-width: 0;
}

.food-drink-info-section__header {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 1rem;
  min-width: 0;
  padding: 0 0.15rem 0.15rem;
}

.food-drink-info-section__header::after {
  display: block;
  width: min(18rem, 42%);
  height: 1px;
  flex: 0 1 auto;
  background: linear-gradient(
    90deg,
    rgba(38, 57, 111, 0.2),
    rgba(38, 57, 111, 0)
  );
  content: "";
}

.food-drink-info-section__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: var(--info-font-size--title);
  font-weight: 900;
  line-height: 1.2;
}

.food-drink-info-section__cards {
  display: grid;
  gap: 0.9rem;
  min-width: 0;
}

.food-drink-info-section__cards--options {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr));
  align-items: start;
}

.food-drink-info-block {
  min-width: 0;
  padding: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.74);
  border-radius: calc(var(--field-radius) + 3px);
  background: var(--info-card-bg);
  box-shadow: var(--info-card-shadow);
}

.food-drink-info-block__title {
  margin: 0 0 0.65rem;
  color: var(--color-main-blue-dark);
  font-size: 1.02rem;
  font-weight: 900;
  line-height: 1.15;
}

.food-drink-info-list,
.food-drink-info-notes {
  display: grid;
  gap: 0.48rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.food-drink-info-row {
  display: grid;
  grid-template-columns: minmax(12rem, max-content) minmax(0, 1fr);
  align-items: center;
  gap: 0.8rem;
  min-width: 0;
  padding: 0.56rem 0;
  border-bottom: 1px solid rgba(38, 57, 111, 0.09);
  color: rgba(8, 21, 64, 0.78);
  font-size: 0.9rem;
  line-height: 1.4;
}

.food-drink-info-row:last-child {
  border-bottom: 0;
}

.food-drink-info-row--priced {
  grid-template-columns: minmax(11rem, max-content) minmax(0, 1fr);
}

.food-drink-info-row__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.food-drink-info-pill,
.food-drink-info-price {
  display: inline-flex;
  align-items: center;
  width: fit-content;
  max-width: 100%;
  min-height: 1.7rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 800;
  line-height: 1.15;
}

.food-drink-info-pill {
  padding: 0.25rem 0.52rem;
  border: 1px solid rgba(38, 57, 111, 0.18);
  background:
    linear-gradient(
      180deg,
      rgba(248, 252, 255, 0.98),
      rgba(232, 240, 255, 0.92)
    );
  color: rgba(8, 21, 64, 0.9);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    0 2px 6px rgba(8, 21, 64, 0.035);
}

.food-drink-info-pill::before {
  width: 0.38rem;
  height: 0.38rem;
  margin-right: 0.4rem;
  border-radius: 999px;
  background: rgba(38, 57, 111, 0.48);
  content: "";
}

.food-drink-info-price {
  flex-shrink: 0;
  justify-self: end;
  padding: 0.25rem 0.56rem;
  border: 1px solid color-mix(in srgb, var(--color-accent-warm-muted) 26%, transparent);
  background:
    linear-gradient(
      180deg,
      var(--color-accent-warm-softer),
      var(--color-accent-warm-soft)
    );
  color: var(--color-main-blue-dark);
  white-space: nowrap;
}

.food-drink-info-row__description {
  min-width: 0;
  overflow-wrap: normal;
}

.food-drink-info-notes {
  gap: 0.45rem;
  color: rgba(8, 21, 64, 0.78);
  font-size: 0.9rem;
  line-height: 1.45;
}

.food-drink-info-notes li {
  padding-left: 1.05rem;
  position: relative;
}

.food-drink-info-notes li::before {
  position: absolute;
  top: 0.58em;
  left: 0;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: rgba(38, 57, 111, 0.48);
  content: "";
}

@media (max-width: 680px) {
  .food-drink-info-card__layout {
    gap: 1.2rem;
  }

  .food-drink-info-section__header {
    display: grid;
    gap: 0.45rem;
  }

  .food-drink-info-section__header::after {
    width: 100%;
  }

  .food-drink-info-section__cards--options {
    grid-template-columns: 1fr;
  }

  .food-drink-info-row,
  .food-drink-info-row--priced {
    grid-template-columns: 1fr;
    align-items: start;
  }

  .food-drink-info-row__body {
    grid-template-columns: 1fr;
    gap: 0.45rem;
  }

  .food-drink-info-price {
    justify-self: start;
  }
}
</style>
