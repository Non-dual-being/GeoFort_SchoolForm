<script setup lang="ts">
import type { RevenueAmounts } from "../../types/bookingRevenue";
import { formatEuroCents } from "../../utils/money";

defineProps<{ title: string; description: string; amounts: RevenueAmounts }>();
</script>

<template>
  <article class="admin-revenue-breakdown">
    <h3>{{ title }}</h3><p>{{ description }}</p>
    <dl><div><dt>Bezoek incl. btw</dt><dd>{{ formatEuroCents(amounts.visitInclVatCents) }}</dd></div><div><dt>Catering incl. btw</dt><dd>{{ formatEuroCents(amounts.cateringInclVatCents) }}</dd></div><div class="admin-revenue-breakdown__total"><dt>Totaal incl. btw</dt><dd>{{ formatEuroCents(amounts.totalInclVatCents) }}</dd></div><div><dt>Totaal excl. btw</dt><dd>{{ formatEuroCents(amounts.totalExclVatCents) }}</dd></div><div><dt>Btw</dt><dd>{{ formatEuroCents(amounts.vatCents) }}</dd></div></dl>
  </article>
</template>
