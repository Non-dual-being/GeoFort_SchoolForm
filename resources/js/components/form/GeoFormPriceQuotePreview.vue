<script setup lang="ts">
import { computed } from "vue";
import { ReceiptText } from "lucide-vue-next";

import type {
  BookingPriceLineDto,
  BookingPriceQuoteDto,
} from "../../types/booking/BookingPriceQuoteTypes";

const props = defineProps<{
  id: string;
  label: string;
  quote: BookingPriceQuoteDto | null;
  isLoading: boolean;
  errorMessage?: string | null;
  formatCurrency: (amount: number) => string;
}>();

const hasOnlyFreeSupervisors = computed(() => {
  return props.quote !== null && props.quote.visit.paidSupervisors === 0;
});

function lineAmount(line: BookingPriceLineDto): string {
  return props.formatCurrency(line.totalInclVatCents / 100);
}

function lineUnitPrice(line: BookingPriceLineDto, unitLabel: string): string {
  return `${props.formatCurrency(line.unitPriceInclVatCents / 100)} ${unitLabel}`;
}
</script>

<template>
  <section class="price-quote-field">
    <div
      class="input-label"
      :id="`${id}-label`"
    >
      <span
        class="input-label__icon price-quote"
        aria-hidden="true"
      >
        <ReceiptText
          :size="17"
          :stroke-width="2.4"
        />
      </span>

      <span>{{ label }}</span>
    </div>

    <div
      class="price-quote-card"
      :aria-labelledby="`${id}-label`"
    >
      <div
        v-if="isLoading"
        class="price-quote-notice"
      >
        Prijsopgave ophalen...
      </div>

      <div
        v-else-if="errorMessage"
        class="price-quote-notice price-quote-notice--error"
      >
        {{ errorMessage }}
      </div>

      <template v-else-if="quote">
        <section class="price-quote-section">
          <h3 class="price-quote-section__title">Bezoek</h3>

          <dl class="price-quote-rows">
            <div
              v-for="line in quote.visit.lines"
              :key="line.key"
              class="price-quote-row"
            >
              <dt>
                <span class="price-quote-row__label">{{ line.label }}</span>
                <span class="price-quote-row__unit">{{ lineUnitPrice(line, "pp") }}</span>
              </dt>
              <dd>{{ lineAmount(line) }}</dd>
            </div>

            <div
              v-if="hasOnlyFreeSupervisors"
              class="price-quote-hint"
            >
              Alle begeleiders vallen binnen het gratis aantal.
            </div>

            <div class="price-quote-row price-quote-row--subtotal">
              <dt>Totale bezoekprijs</dt>
              <dd>{{ formatCurrency(quote.visit.amountInclVatCents / 100) }}</dd>
            </div>

            <div class="price-quote-row">
              <dt>Totale bezoekprijs excl. btw ({{ quote.vatBasisPoints / 100 }}%)</dt>
              <dd>{{ formatCurrency(quote.visit.amountExclVatCents / 100) }}</dd>
            </div>
          </dl>
        </section>

        <section class="price-quote-section">
          <h3 class="price-quote-section__title">Eten en drinken</h3>

          <div
            v-if="quote.foodAndDrink.lines.length === 0"
            class="price-quote-empty"
          >
            Geen eten of drinken besteld.
          </div>

          <dl
            v-else
            class="price-quote-rows"
          >
            <div
              v-for="line in quote.foodAndDrink.lines"
              :key="line.key"
              class="price-quote-row"
            >
              <dt>
                <span class="price-quote-row__label">{{ line.label }}</span>
                <span class="price-quote-row__unit">{{ lineUnitPrice(line, "per stuk") }}</span>
              </dt>
              <dd>{{ lineAmount(line) }}</dd>
            </div>

            <div class="price-quote-row price-quote-row--subtotal">
              <dt>Totale bestelprijs</dt>
              <dd>{{ formatCurrency(quote.foodAndDrink.amountInclVatCents / 100) }}</dd>
            </div>

            <div class="price-quote-row">
              <dt>Totale bestelprijs excl. btw ({{ quote.vatBasisPoints / 100 }}%)</dt>
              <dd>{{ formatCurrency(quote.foodAndDrink.amountExclVatCents / 100) }}</dd>
            </div>
          </dl>
        </section>

        <section class="price-quote-section price-quote-section--total">
          <h3 class="price-quote-section__title">Totaal</h3>

          <dl class="price-quote-rows">
            <div class="price-quote-row price-quote-row--grand">
              <dt>Totale prijs</dt>
              <dd>{{ formatCurrency(quote.total.amountInclVatCents / 100) }}</dd>
            </div>

            <div class="price-quote-row">
              <dt>Totale prijs excl. btw</dt>
              <dd>{{ formatCurrency(quote.total.amountExclVatCents / 100) }}</dd>
            </div>
          </dl>
        </section>
      </template>
    </div>
  </section>
</template>

<style scoped>
.price-quote-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: var(--field-gap);
  margin-top: 1.05rem;
  margin-bottom: var(--field-stack-gap);
}

.input-label__icon.price-quote {
  display: inline-flex;
  align-items: center;
  color: var(--color-main-blue);
}

.price-quote-card {
  display: grid;
  gap: 0.9rem;
  min-width: 0;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background: rgba(255, 244, 244, 0.58);
  box-shadow:
    0 0.55rem 1.15rem rgba(8, 21, 64, 0.065),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.price-quote-section {
  display: grid;
  gap: 0.7rem;
  min-width: 0;
  padding: 0.85rem;
  border: 1px solid rgba(38, 57, 111, 0.13);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.84);
}

.price-quote-section--total {
  border: 1px solid rgba(38, 57, 111, 0.24);
  border-top: 3px solid rgba(38, 57, 111, 0.42);
  background: rgba(255, 255, 255, 0.98);
  box-shadow:
    0 0.45rem 1rem rgba(8, 21, 64, 0.075),
    inset 0 1px 0 rgba(255, 255, 255, 0.92);
}

.price-quote-section__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.2;
}

.price-quote-rows {
  display: grid;
  gap: 0.45rem;
  margin: 0;
}

.price-quote-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.85rem;
  align-items: baseline;
  min-width: 0;
  padding: 0.42rem 0;
  border-bottom: 1px solid rgba(38, 57, 111, 0.09);
}

.price-quote-row:last-child {
  border-bottom: 0;
}

.price-quote-row dt {
  display: grid;
  gap: 0.16rem;
  min-width: 0;
  color: rgba(8, 21, 64, 0.78);
  font-size: 0.9rem;
  font-weight: 720;
  line-height: 1.3;
}

.price-quote-row__label,
.price-quote-row__unit {
  min-width: 0;
}

.price-quote-row__unit {
  color: rgba(8, 21, 64, 0.58);
  font-size: 0.78rem;
  font-weight: 760;
}

.price-quote-row dd {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 0.95rem;
  font-weight: 900;
  line-height: 1.2;
  text-align: right;
  white-space: nowrap;
}

.price-quote-row--subtotal dt,
.price-quote-row--subtotal dd,
.price-quote-row--grand dt,
.price-quote-row--grand dd {
  color: var(--color-main-blue-dark);
  font-weight: 950;
}

.price-quote-row--grand dd {
  font-size: 1.14rem;
}

.price-quote-hint,
.price-quote-empty,
.price-quote-notice {
  padding: 0.68rem 0.78rem;
  border: 1px solid rgba(38, 57, 111, 0.12);
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(243, 248, 255, 0.9)
    );
  color: rgba(8, 21, 64, 0.74);
  font-size: 0.88rem;
  font-weight: 720;
  line-height: 1.35;
}

.price-quote-notice--error {
  border-color: rgba(217, 49, 52, 0.24);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(255, 246, 246, 0.88)
    );
  color: rgba(126, 28, 31, 0.86);
}

@media (max-width: 620px) {
  .price-quote-row {
    grid-template-columns: 1fr;
    gap: 0.26rem;
  }

  .price-quote-row dd {
    text-align: left;
  }
}
</style>
