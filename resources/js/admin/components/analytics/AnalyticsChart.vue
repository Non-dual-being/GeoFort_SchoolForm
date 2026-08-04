<script setup lang="ts">
import { Chart, registerables, type ChartConfiguration, type ChartData } from "chart.js";
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = withDefaults(defineProps<{
  config: ChartConfiguration;
  label: string;
  visibilityHint?: boolean;
  allowZeroData?: boolean;
  monthlyWindow?: boolean;
  emptyMessage?: string;
}>(), { visibilityHint: true, allowZeroData: false, monthlyWindow: false, emptyMessage: "Geen grafiekdata binnen deze selectie. De tabel blijft beschikbaar." });
const emit = defineEmits<{ select: [datasetIndex: number, index: number] }>();
const root = ref<HTMLElement | null>(null);
const viewport = ref<HTMLElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const state = ref<"loading" | "ready" | "empty" | "error">("loading");
let chart: Chart | null = null;
let resizeObserver: ResizeObserver | null = null;
let frame = 0;
let generation = 0;
const viewportWidth = ref(0);
Chart.register(...registerables);

const visibleMonths = computed(() => viewportWidth.value < 620 ? 3 : viewportWidth.value < 1500 ? 4 : 6);
const monthCount = computed(() => props.config.data.labels?.length ?? 0);
const scrollable = computed(() => props.monthlyWindow && monthCount.value > visibleMonths.value);
const chartWidth = computed(() => !props.monthlyWindow || !scrollable.value ? "100%" : `${Math.ceil(viewportWidth.value / visibleMonths.value * monthCount.value)}px`);

const hasData = computed(() => props.config.data.datasets.some((dataset) =>
  Array.isArray(dataset.data) && dataset.data.some((value) => {
    if (typeof value === "number") return Number.isFinite(value) && (props.allowZeroData || value !== 0);
    if (value && typeof value === "object" && "value" in value) return Number(value.value) !== 0;
    if (value && typeof value === "object" && "y" in value) return Number(value.y) !== 0;
    return false;
  })));

function measurable(): boolean {
  const rect = canvas.value?.parentElement?.getBoundingClientRect();
  return Boolean(rect && rect.width > 0 && rect.height > 0);
}

async function initialize(attempt = 0, expectedGeneration = generation): Promise<void> {
  await nextTick();
  if (expectedGeneration !== generation || chart || !canvas.value) return;
  if (!hasData.value) { state.value = "empty"; return; }
  if (!measurable()) {
    if (attempt < 12) frame = requestAnimationFrame(() => void initialize(attempt + 1, expectedGeneration));
    else state.value = "error";
    return;
  }
  try {
    chart = markRaw(new Chart(canvas.value, {
      ...props.config,
      options: {
        ...props.config.options,
        onClick: (_event, elements) => {
          const element = elements[0];
          if (element) emit("select", element.datasetIndex, element.index);
        },
      },
    } as ChartConfiguration));
    state.value = "ready";
    chart.resize();
  } catch {
    chart = null;
    state.value = "error";
  }
}

function update(): void {
  if (!hasData.value) {
    destroyChart();
    state.value = "empty";
    return;
  }
  if (!chart) {
    state.value = "loading";
    void initialize();
    return;
  }
  try {
    chart.data = props.config.data as ChartData;
    chart.options = { ...props.config.options, onClick: chart.options.onClick };
    chart.update("none");
    if (props.visibilityHint) chart.resize();
    state.value = "ready";
  } catch {
    destroyChart();
    state.value = "error";
  }
}

function retry(): void {
  destroyChart();
  state.value = "loading";
  generation += 1;
  void initialize(0, generation);
}

function destroyChart(): void {
  cancelAnimationFrame(frame);
  chart?.destroy();
  chart = null;
}

onMounted(() => {
  if (typeof ResizeObserver !== "undefined") {
    resizeObserver = new ResizeObserver(() => {
      if (!chart && measurable()) void initialize();
      viewportWidth.value = viewport.value?.clientWidth ?? 0;
      nextTick(() => chart?.resize());
    });
    if (viewport.value) resizeObserver.observe(viewport.value);
  }
  void initialize();
});
watch(() => props.config, update, { deep: true });
watch(() => props.visibilityHint, (visible) => { if (visible) { if (!chart) void initialize(); else chart.resize(); } });
onBeforeUnmount(() => {
  generation += 1;
  resizeObserver?.disconnect();
  resizeObserver = null;
  destroyChart();
});
</script>

<template>
  <div ref="root" class="admin-analytics-chart" :class="{ 'admin-analytics-chart--monthly': monthlyWindow }" :data-chart-state="state">
    <div v-if="state === 'loading'" class="admin-analytics-chart__state" role="status">Grafiek laden…</div>
    <div v-else-if="state === 'empty'" class="admin-analytics-chart__state">{{ emptyMessage }}</div>
    <div v-else-if="state === 'error'" class="admin-analytics-chart__state" role="alert">
      <p>De grafiek kon niet worden getekend. De tabelgegevens zijn wel beschikbaar.</p>
      <button type="button" class="admin-button admin-button--secondary" @click="retry">Grafiek opnieuw laden</button>
    </div>
    <div ref="viewport" class="admin-analytics-chart__viewport" :tabindex="scrollable ? 0 : -1" :aria-label="scrollable ? `${label}: horizontaal scrollbaar maandvenster` : undefined">
      <div class="admin-analytics-chart__canvas" :style="{ width: chartWidth }"><canvas ref="canvas" role="img" :aria-label="label" :aria-hidden="state !== 'ready'" :tabindex="state === 'ready' ? 0 : -1" /></div>
    </div>
    <p v-if="scrollable && state === 'ready'" class="admin-analytics-chart__scroll-hint">Scroll horizontaal voor meer maanden</p>
  </div>
</template>
