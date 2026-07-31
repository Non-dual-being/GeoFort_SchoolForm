<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import AnalyticsChart from "../components/analytics/AnalyticsChart.vue";
import AnalyticsTable from "../components/analytics/AnalyticsTable.vue";
import AdminButton from "../components/form/AdminButton.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import type { AdminSelectOption } from "../components/form/types";
import { buildCateringChart, buildSchoolOccupancyChart, buildSeasonChart, buildStudentChart, buildTopDaysChart, buildYearChart, reducedMotionConfig } from "../analytics/chartBuilders";
import { fetchBookingAnalytics } from "../services/dashboardBookingAnalyticsApi";
import type { BookingAnalyticsResponse, PopulationFilter, ProgramFilter, SeasonMetric, SeasonView, SectorFilter, WeekdayBucket, YearMetric } from "../types/bookingAnalytics";

const route = useRoute();
const router = useRouter();
const data = ref<BookingAnalyticsResponse | null>(null);
const startDate = ref(validDate(route.query.start) ?? "");
const endDate = ref(validDate(route.query.end) ?? "");
const sector = ref<SectorFilter>(validSector(route.query.sector));
const population = ref<PopulationFilter>(validPopulation(route.query.population));
const program = ref<ProgramFilter>(validProgram(route.query.program));
const loading = ref(true);
const error = ref(false);
const initialized = ref(false);
const activeSection = ref("overview");
const visibleSections = ref(new Set<string>(["overview"]));
const studentMode = ref<"students" | "capacity">("students");
const studentDisplay = ref<"count" | "percentage">("count");
const cateringMode = ref<"school" | "booking">("school");
const yearMetric = ref<YearMetric>("plannedStudents");
const seasonLevel = ref<SeasonView>(validSeasonView(route.query.seasonView));
const seasonMetric = ref<SeasonMetric>(seasonLevel.value === "weekday" ? "averageStudentsPerVisitDate" : "students");
const yearMix = ref<"program" | "sector">("program");
const presentations = ref<Record<string, "chart" | "table" | "both">>({ volume: "both", catering: "both", development: "both", season: "both" });
const selectedStudent = ref<{ dataset: number; index: number } | null>(null);
const reducedMotion = ref(false);
const revealEnhanced = ref(false);
let controller: AbortController | undefined;
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;
let sectionObserver: IntersectionObserver | undefined;
let revealObserver: IntersectionObserver | undefined;
let revealFallback: ReturnType<typeof setTimeout> | undefined;
let scrollFrame = 0;

const periodError = computed(() => startDate.value && endDate.value && startDate.value > endDate.value ? "De einddatum mag niet vóór de begindatum liggen." : "");
const hasBookings = computed(() => data.value?.dateBounds.minDate !== null);
const isEmpty = computed(() => data.value ? Object.values(data.value.summary).every((value) => value === 0) : true);
const nf = new Intl.NumberFormat("nl-NL", { maximumFractionDigits: 1 });
const pf = new Intl.NumberFormat("nl-NL", { maximumFractionDigits: 1 });
const sections = [
  { id: "overview", label: "Overzicht" }, { id: "volume", label: "Bezoekomvang" },
  { id: "catering", label: "Catering" }, { id: "development", label: "Ontwikkeling" }, { id: "season", label: "Seizoen" },
];
const sectorOptions: readonly AdminSelectOption[] = [
  { value: "all", label: "Alle sectoren" }, { value: "primairOnderwijs", label: "Primair onderwijs" },
  { value: "voortgezetOnderbouw", label: "VO onderbouw" }, { value: "voortgezetBovenbouw", label: "VO bovenbouw" },
];
const populationOptions: readonly AdminSelectOption[] = [
  { value: "planning", label: "Planning: optie en definitief" }, { value: "confirmed", label: "Alleen definitief" }, { value: "all", label: "Alle aanvragen" },
];
const programOptions: readonly AdminSelectOption[] = [{ value: "all", label: "Alle programma’s" }, { value: "dag", label: "Dagprogramma" }, { value: "ochtend", label: "Ochtendprogramma" }];
const seasonLevelOptions: readonly AdminSelectOption[] = [{ value: "monthly", label: "Per maand" }, { value: "weekday", label: "Per weekdag" }];
const studentModeOptions: readonly AdminSelectOption[] = [{ value: "students", label: "Leerlingenaantal" }, { value: "capacity", label: "Capaciteitsbenutting" }];
const displayOptions: readonly AdminSelectOption[] = [{ value: "count", label: "Aantallen" }, { value: "percentage", label: "Percentages" }];
const presentationOptions: readonly AdminSelectOption[] = [{ value: "chart", label: "Grafiek" }, { value: "table", label: "Tabel" }, { value: "both", label: "Beide" }];
const cateringOptions: readonly AdminSelectOption[] = [{ value: "school", label: "Per unieke school" }, { value: "booking", label: "Per aanvraag" }];
const mixOptions: readonly AdminSelectOption[] = [{ value: "program", label: "Programma" }, { value: "sector", label: "Onderwijssector" }];
const yearMetricOptions = computed<AdminSelectOption[]>(() => Object.entries(data.value?.yearlyAnalysis.availableMetrics ?? {}).map(([value, label]) => ({ value, label })));
const seasonMetricOptions = computed<AdminSelectOption[]>(() => Object.entries(data.value?.seasonalityAnalysis.availableMetricsByView[seasonLevel.value] ?? {}).map(([value, label]) => ({ value, label })));
const kpis = computed(() => data.value ? [
  ["Actieve aanvragen", data.value.summary.activeBookings], ["Definitieve aanvragen", data.value.summary.confirmedBookings],
  ["Aanvragen in optie", data.value.summary.optionBookings], ["Afgewezen aanvragen", data.value.summary.rejectedBookings],
  ["Geplande leerlingen", data.value.summary.plannedStudents], ["Unieke scholen", data.value.summary.uniqueSchools],
  ["Unieke bezoekdagen", data.value.summary.uniqueVisitDays], ["Gem. leerlingen per actieve aanvraag", data.value.summary.averageStudentsPerActiveBooking],
  ["Gem. leerlingen per bezoekdag", data.value.summary.averageStudentsPerVisitDay], ["Afwijzingspercentage", `${data.value.summary.rejectionPercentage}%`],
] : []);
const studentChart = computed(() => data.value ? reducedMotionConfig(buildStudentChart(data.value.studentCountAnalysis, studentMode.value, studentDisplay.value), reducedMotion.value) : null);
const cateringChart = computed(() => data.value ? reducedMotionConfig(buildCateringChart(data.value.cateringAnalysis, cateringMode.value), reducedMotion.value) : null);
const yearChart = computed(() => data.value ? reducedMotionConfig(buildYearChart(data.value.yearlyAnalysis, yearMetric.value), reducedMotion.value) : null);
const seasonRows = computed(() => !data.value ? [] : seasonLevel.value === "monthly" ? data.value.seasonalityAnalysis.monthlyBuckets.map((row) => ({ ...row, visitDays: row.uniqueVisitDates, schools: row.uniqueSchools, average: row.averageStudentsPerVisitDate })) : data.value.seasonalityAnalysis.weekdayBuckets);
const seasonChart = computed(() => data.value ? reducedMotionConfig(buildSeasonChart(seasonLevel.value === "monthly" ? data.value.seasonalityAnalysis.monthlyBuckets : data.value.seasonalityAnalysis.weekdayBuckets, seasonLevel.value, seasonMetric.value), reducedMotion.value) : null);
const rankedTopDays = computed(() => data.value?.seasonalityAnalysis.topDays ?? []);
const topDaysChart = computed(() => data.value ? reducedMotionConfig(buildTopDaysChart(rankedTopDays.value, "students"), reducedMotion.value) : null);
const occupancyChart = computed(() => data.value && data.value.seasonalityAnalysis.schoolOccupancy.totalBookedVisitDates > 0 ? reducedMotionConfig(buildSchoolOccupancyChart(data.value.seasonalityAnalysis.schoolOccupancy), reducedMotion.value) : null);
const occupancyRows = computed(() => data.value?.seasonalityAnalysis.schoolOccupancy.categories.map((row) => ({ label: row.label, visitDateCount: row.visitDateCount, percentage: row.percentageOfBookedVisitDates, bookings: row.bookingCount, students: row.studentCount, averageStudents: row.averageStudentsPerVisitDate })) ?? []);
const occupancyTotals = computed(() => {
  const total = occupancyRows.value.reduce((sum, row) => ({ visitDateCount: sum.visitDateCount + row.visitDateCount, bookings: sum.bookings + row.bookings, students: sum.students + row.students }), { visitDateCount: 0, bookings: 0, students: 0 });
  return { label: "Totaal", ...total, percentage: total.visitDateCount > 0 ? 100 : 0, averageStudents: total.visitDateCount > 0 ? total.students / total.visitDateCount : 0 };
});
const seasonContext = computed(() => {
  const rows = seasonRows.value;
  if (!rows.length) return null;
  const value = (row: typeof rows[number]) => Number(row[seasonMetric.value as keyof typeof row] ?? 0);
  const busiest = [...rows].sort((a, b) => value(b) - value(a) || Number(b.students) - Number(a.students) || Number(b.bookings) - Number(a.bookings) || Number((a as WeekdayBucket).weekdayNumber ?? 0) - Number((b as WeekdayBucket).weekdayNumber ?? 0))[0]!;
  return { busiestLabel: seasonLevel.value === "monthly" ? String("label" in busiest ? busiest.label : "") : String("weekdayLabel" in busiest ? busiest.weekdayLabel : ""), metricLabel: seasonMetricOptions.value.find((option) => option.value === seasonMetric.value)?.label ?? "Waarde", metricValue: value(busiest), students: Number(busiest.students), bookings: Number(busiest.bookings), visitDays: Number("uniqueVisitDates" in busiest ? busiest.uniqueVisitDates : 0), averageStudents: Number("averageStudentsPerVisitDate" in busiest ? busiest.averageStudentsPerVisitDate : 0), averageSchools: Number("averageSchoolsPerVisitDate" in busiest ? busiest.averageSchoolsPerVisitDate : 0) };
});
const studentRows = computed(() => {
  if (!data.value) return [];
  const bins = studentMode.value === "students" ? data.value.studentCountAnalysis.bins : data.value.studentCountAnalysis.capacityBins;
  const day = data.value.studentCountAnalysis.programs.find((row) => row.key === "dag");
  const morning = data.value.studentCountAnalysis.programs.find((row) => row.key === "ochtend");
  return bins.map((bin, index) => {
    const d = (studentMode.value === "students" ? day?.studentBins : day?.capacityBins)?.[index];
    const m = (studentMode.value === "students" ? morning?.studentBins : morning?.capacityBins)?.[index];
    return { label: bin.label, dayCount: d?.bookings ?? 0, dayPercentage: d?.percentage ?? 0, morningCount: m?.bookings ?? 0, morningPercentage: m?.percentage ?? 0 };
  });
});
const selectedStudentDetail = computed(() => {
  if (!data.value || !selectedStudent.value) return null;
  const program = data.value.studentCountAnalysis.programs[selectedStudent.value.dataset];
  return program ? (studentMode.value === "students" ? program.studentBins : program.capacityBins)[selectedStudent.value.index] : null;
});
const yearRows = computed(() => data.value?.yearlyAnalysis.years.map((row, index, rows) => {
  const previous = rows[index - 1];
  const comparable = previous?.comparisonStatus === "comparable" && row.comparisonStatus === "comparable";
  const value = row.metrics[yearMetric.value];
  const difference = previous ? value - previous.metrics[yearMetric.value] : null;
  const percentage = comparable && previous && previous.metrics[yearMetric.value] !== 0 ? difference! / previous.metrics[yearMetric.value] * 100 : null;
  return { year: row.year, value, difference: difference ?? "—", differencePercentage: percentage === null ? "Niet vergelijkbaar" : `${pf.format(percentage)}%`, status: comparisonLabel(row.comparisonStatus) };
}) ?? []);

async function load(usePeriod = true): Promise<void> {
  if (usePeriod && (!startDate.value || !endDate.value || periodError.value)) return;
  controller?.abort();
  const current = new AbortController();
  controller = current;
  const request = ++sequence;
  loading.value = true; error.value = false;
  try {
    const result = await fetchBookingAnalytics(usePeriod ? startDate.value : undefined, usePeriod ? endDate.value : undefined, current.signal, sector.value, population.value, program.value);
    if (request !== sequence) return;
    data.value = result;
    if (!initialized.value && result.dateBounds.minDate && result.dateBounds.maxDate) {
      startDate.value ||= result.dateBounds.minDate; endDate.value ||= result.dateBounds.maxDate;
    }
    initialized.value = true;
    await syncUrl();
    await nextTick();
    observeSections();
  } catch (caught) {
    if (!(caught instanceof DOMException && caught.name === "AbortError") && request === sequence) error.value = true;
  } finally {
    if (request === sequence && !current.signal.aborted) loading.value = false;
  }
}
function schedule(): void {
  if (!initialized.value) return;
  clearTimeout(timer); controller?.abort(); selectedStudent.value = null;
  if (!startDate.value || !endDate.value || periodError.value) { loading.value = false; return; }
  loading.value = true; timer = setTimeout(() => void load(), 300);
}
function resetFilters(): void {
  if (!data.value?.dateBounds.minDate || !data.value.dateBounds.maxDate) return;
  startDate.value = data.value.dateBounds.minDate; endDate.value = data.value.dateBounds.maxDate;
  sector.value = "all"; population.value = "planning";
  program.value = "all";
}
async function syncUrl(): Promise<void> {
  if (!startDate.value || !endDate.value) return;
  await router.replace({ query: { start: startDate.value, end: endDate.value, sector: sector.value, population: population.value, program: program.value, seasonView: seasonLevel.value, section: route.query.section } });
}
function scrollTo(id: string): void {
  activeSection.value = id;
  void router.replace({ query: { ...route.query, section: id === "overview" ? undefined : id } });
  document.getElementById(`analytics-${id}`)?.scrollIntoView({ behavior: reducedMotion.value ? "auto" : "smooth", block: "start" });
}
function observeSections(): void {
  sectionObserver?.disconnect(); revealObserver?.disconnect();
  const elements = sections.map(({ id }) => document.getElementById(`analytics-${id}`)).filter(Boolean) as HTMLElement[];
  if (reducedMotion.value || typeof IntersectionObserver === "undefined") {
    revealEnhanced.value = false;
    elements.forEach((element) => element.classList.add("is-visible"));
    return;
  }
  revealEnhanced.value = true;
  elements.forEach((element) => element.classList.add("is-reveal-pending"));
  const offset = scrollOffset();
  sectionObserver = new IntersectionObserver(() => handleScrollSpy(), { root: null, rootMargin: `-${offset}px 0px -${Math.max(0, window.innerHeight - offset - 2)}px 0px`, threshold: 0 });
  revealObserver = new IntersectionObserver((entries) => entries.forEach((entry) => {
    if (entry.isIntersecting) { visibleSections.value = new Set([...visibleSections.value, entry.target.id.replace("analytics-", "")]); entry.target.classList.remove("is-reveal-pending"); entry.target.classList.add("is-visible"); }
  }), { rootMargin: "100px", threshold: 0.08 });
  elements.forEach((element) => { sectionObserver?.observe(element); revealObserver?.observe(element); });
  clearTimeout(revealFallback);
  revealFallback = setTimeout(() => elements.forEach((element) => {
    element.classList.remove("is-reveal-pending");
    element.classList.add("is-visible");
  }), 800);
}
function handleScrollSpy(): void {
  cancelAnimationFrame(scrollFrame);
  scrollFrame = requestAnimationFrame(() => {
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 12) {
      activeSection.value = "season";
      return;
    }
    const offset = scrollOffset();
    const passed = sections.filter(({ id }) => (document.getElementById(`analytics-${id}`)?.getBoundingClientRect().top ?? Infinity) <= offset);
    activeSection.value = passed.at(-1)?.id ?? "overview";
  });
}
function validDate(value: unknown): string | null { return typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null; }
function validSector(value: unknown): SectorFilter { return ["all","primairOnderwijs","voortgezetOnderbouw","voortgezetBovenbouw"].includes(String(value)) ? value as SectorFilter : "all"; }
function validPopulation(value: unknown): PopulationFilter { return ["planning","confirmed","all"].includes(String(value)) ? value as PopulationFilter : "planning"; }
function validProgram(value: unknown): ProgramFilter { return ["all","dag","ochtend"].includes(String(value)) ? value as ProgramFilter : "all"; }
function validSeasonView(value: unknown): SeasonView { return value === "weekday" || value === "daily" || value === "day" ? "weekday" : "monthly"; }
function scrollOffset(): number { const page = document.querySelector<HTMLElement>(".admin-analytics-page"); return Number.parseFloat(getComputedStyle(page ?? document.documentElement).getPropertyValue("--analytics-scroll-offset")) || 88; }
function comparisonLabel(value: string): string { return ({ comparable: "Vergelijkbaar", partialSelection: "Gedeeltelijke selectie", currentBookingStand: "Voorlopige boekingsstand", futureBookingStand: "Toekomstige boekingsstand" } as Record<string,string>)[value] ?? value; }
function presentationIncludes(section: string, mode: "chart" | "table"): boolean { return presentations.value[section] === mode || presentations.value[section] === "both"; }
function presentation(section: string): "chart" | "table" | "both" { return presentations.value[section] ?? "both"; }
function setPresentation(section: string, value: string): void {
  if (value === "chart" || value === "table" || value === "both") presentations.value[section] = value;
}

watch([startDate, endDate, sector, population, program], schedule);
watch(seasonLevel, (level) => {
  const available = data.value?.seasonalityAnalysis.availableMetricsByView[level] ?? {};
  if (!(seasonMetric.value in available)) seasonMetric.value = level === "weekday" ? "averageStudentsPerVisitDate" : "students";
  if (initialized.value) void syncUrl();
});
watch([studentMode, cateringMode], () => { selectedStudent.value = null; });
onMounted(() => { reducedMotion.value = matchMedia("(prefers-reduced-motion: reduce)").matches; window.addEventListener("scroll", handleScrollSpy, { passive: true }); void load(false).then(() => {
  const requestedSection = typeof route.query.section === "string" && sections.some((item) => item.id === route.query.section) ? route.query.section : null;
  if (requestedSection) requestAnimationFrame(() => scrollTo(requestedSection));
}); });
onBeforeUnmount(() => { clearTimeout(timer); clearTimeout(revealFallback); cancelAnimationFrame(scrollFrame); window.removeEventListener("scroll", handleScrollSpy); controller?.abort(); sectionObserver?.disconnect(); revealObserver?.disconnect(); });
</script>

<template>
  <section class="admin-analytics-page" :class="{ 'is-reveal-enhanced': revealEnhanced, 'is-updating': loading && data }" aria-labelledby="analytics-title">
    <header class="admin-analytics-hero">
      <p class="admin-eyebrow">Managementinformatie</p><h1 id="analytics-title">Onderwijsanalytics</h1>
      <p>Van bezoekomvang tot seizoensdrukte: betrouwbare boekingsinformatie op basis van bezoekdatum.</p>
    </header>
    <nav class="admin-analytics-subnav" aria-label="Analytics-hoofdstukken">
      <button v-for="item in sections" :key="item.id" type="button" :class="{ 'is-active': activeSection === item.id }" :aria-current="activeSection === item.id ? 'location' : undefined" @click="scrollTo(item.id)">{{ item.label }}</button>
    </nav>
    <div class="sr-only" role="status" aria-live="polite">{{ loading ? "Analytics worden geladen." : error ? "Laden mislukt." : "Analytics zijn bijgewerkt." }}</div>

    <section v-if="data && hasBookings" class="admin-analytics-filter-card" aria-labelledby="analytics-filter-title">
      <div><p class="admin-eyebrow">Selectie</p><h2 id="analytics-filter-title">Filters</h2></div>
      <div class="admin-analytics-filters">
        <AdminDateField v-model="startDate" name="analytics-start" label="Startdatum" :min="data.dateBounds.minDate || undefined" :max="endDate || data.dateBounds.maxDate || undefined" :error="periodError || null" required />
        <AdminDateField v-model="endDate" name="analytics-end" label="Einddatum" :min="startDate || data.dateBounds.minDate || undefined" :max="data.dateBounds.maxDate || undefined" :error="periodError || null" required />
        <AdminSelect v-model="sector" name="analytics-sector" label="Sector" :options="sectorOptions" :allow-empty="false" />
        <AdminSelect v-model="population" name="analytics-population" label="Statuspopulatie" :options="populationOptions" :allow-empty="false" />
        <AdminSelect v-model="program" name="analytics-program" label="Programma" :options="programOptions" :allow-empty="false" />
        <div class="admin-analytics-filter-reset"><AdminButton variant="secondary" @click="resetFilters">Filters herstellen</AdminButton></div>
      </div>
      <div class="admin-analytics-chips" aria-label="Actieve filters"><span>{{ startDate }} t/m {{ endDate }}</span><span v-if="sector !== 'all'">Sectorfilter actief</span><span v-if="population !== 'planning'">Status: {{ population === 'confirmed' ? 'alleen definitief' : 'alle aanvragen' }}</span></div>
    </section>

    <div v-if="loading && !data" class="admin-analytics-skeleton" role="status"><span v-for="item in 10" :key="item" aria-hidden="true" /></div>
    <section v-else-if="error && !data" class="admin-analytics-section" role="alert"><h2>Analytics niet beschikbaar</h2><p>De gegevens konden niet worden geladen.</p><AdminButton @click="load()">Opnieuw proberen</AdminButton></section>
    <section v-else-if="data && !hasBookings" class="admin-analytics-section"><h2>Nog geen boekingen beschikbaar</h2><p>Er zijn geen boekingen met een geldige bezoekdatum.</p></section>
    <template v-else-if="data">
      <section id="analytics-overview" class="admin-analytics-chapter is-visible" aria-labelledby="analytics-summary">
        <div class="admin-analytics-chapter__heading"><span>Overzicht</span><div><h2 id="analytics-summary">Managementsummary</h2><p>Wat staat er binnen de gekozen bezoekperiode gepland?</p></div></div>
        <p><strong>Planningcijfers zijn gebaseerd op In optie en Definitief.</strong></p>
        <div class="admin-analytics-kpis"><article v-for="[label, value] in kpis" :key="String(label)"><h3>{{ label }}</h3><p>{{ typeof value === "number" ? nf.format(value) : value }}</p></article></div>
      </section>
      <div v-if="isEmpty" class="admin-analytics-section">Geen aanvragen binnen deze selectie.</div>
      <template v-else>
        <section id="analytics-volume" class="admin-analytics-chapter" aria-labelledby="volume-title">
          <div class="admin-analytics-chapter__heading"><span>01</span><div><h2 id="volume-title">Bezoekomvang</h2><p>Hoe groot zijn de schoolbezoeken en hoe verschilt dit tussen dag- en ochtendprogramma?</p></div></div>
          <div class="admin-analytics-controls">
            <AdminSelect v-model="studentMode" name="analytics-student-mode" label="Analyse" :options="studentModeOptions" :allow-empty="false" />
            <AdminSelect v-model="studentDisplay" name="analytics-student-display" label="Waarde" :options="displayOptions" :allow-empty="false" />
            <AdminSelect :model-value="presentation('volume')" name="analytics-volume-view" label="Weergave" :options="presentationOptions" :allow-empty="false" @update:model-value="setPresentation('volume', $event)" />
          </div>
          <div class="admin-analytics-visual-grid">
            <AnalyticsChart v-if="studentChart && presentationIncludes('volume','chart')" :config="studentChart" label="Verdeling van leerlingenaantallen per programma" :visibility-hint="visibleSections.has('volume')" @select="(dataset,index) => selectedStudent = { dataset, index }" />
            <aside class="admin-analytics-insight"><strong>Managementcontext</strong><p>{{ data.studentCountAnalysis.context }}</p><p v-if="data.studentCountAnalysis.invalidRecordCount" class="admin-analytics-warning">{{ data.studentCountAnalysis.invalidRecordCount }} record(s) met nul, ontbrekend, negatief of boven 160 leerlingen zijn apart gehouden.</p>
              <div v-if="selectedStudentDetail"><h3>Geselecteerde band</h3><p>{{ selectedStudentDetail.label }} · {{ selectedStudentDetail.bookings }} aanvragen · {{ nf.format(selectedStudentDetail.students) }} leerlingen</p><ul><li v-for="row in selectedStudentDetail.sectorDistribution" :key="row.label">{{ row.label }}: {{ row.count }}</li></ul></div>
            </aside>
          </div>
          <AnalyticsTable v-if="presentationIncludes('volume','table')" caption="Leerlingbanden per programma" :rows="studentRows" :selected-key="selectedStudentDetail?.label ?? null" :columns="[{key:'label',label:'Leerlingband',sortType:'band'},{key:'dayCount',label:'Dag aantal',format:'number'},{key:'dayPercentage',label:'Dag %',format:'percentage'},{key:'morningCount',label:'Ochtend aantal',format:'number'},{key:'morningPercentage',label:'Ochtend %',format:'percentage'}]" />
          <p class="admin-analytics-definition">{{ studentMode === 'students' ? data.studentCountAnalysis.definitions.studentBands : data.studentCountAnalysis.definitions.capacity }}</p>
        </section>

        <section id="analytics-catering" class="admin-analytics-chapter" aria-labelledby="catering-title">
          <div class="admin-analytics-chapter__heading"><span>02</span><div><h2 id="catering-title">Catering</h2><p>Hoeveel scholen nemen catering af en welke profielen worden gekozen?</p></div></div>
          <div class="admin-analytics-controls"><AdminSelect v-model="cateringMode" name="analytics-catering-mode" label="Eenheid" :options="cateringOptions" :allow-empty="false" /><AdminSelect :model-value="presentation('catering')" name="analytics-catering-view" label="Weergave" :options="presentationOptions" :allow-empty="false" @update:model-value="setPresentation('catering', $event)" /></div>
          <div class="admin-analytics-visual-grid"><AnalyticsChart v-if="cateringChart && presentationIncludes('catering','chart')" :config="cateringChart" label="Cateringafname" :visibility-hint="visibleSections.has('catering')" /><aside class="admin-analytics-insight"><strong>Managementcontext</strong><p>{{ data.cateringAnalysis.context }}</p><dl><div><dt>Gem. omvang met catering</dt><dd>{{ nf.format(data.cateringAnalysis.insights.averageStudentsWithCatering ?? 0) }}</dd></div><div><dt>Zonder catering</dt><dd>{{ nf.format(data.cateringAnalysis.insights.averageStudentsWithoutCatering ?? 0) }}</dd></div></dl></aside></div>
          <AnalyticsTable v-if="presentationIncludes('catering','table')" caption="Cateringprofielen" :rows="cateringMode === 'school' ? data.cateringAnalysis.schoolProfiles : data.cateringAnalysis.bookingProfiles" :columns="[{key:'label',label:'Profiel'},{key:'count',label:'Aantal',format:'number'},{key:'percentage',label:'Percentage',format:'percentage'}]" />
          <div class="admin-analytics-mini-tables"><AnalyticsTable caption="Catering per programma" :rows="data.cateringAnalysis.programBreakdown" :columns="[{key:'label',label:'Programma'},{key:'bookings',label:'Aanvragen',format:'number'},{key:'withCatering',label:'Met catering',format:'number'},{key:'percentage',label:'Catering %',format:'percentage'}]" /><AnalyticsTable caption="Catering per sector" :rows="data.cateringAnalysis.sectorBreakdown" :columns="[{key:'label',label:'Sector'},{key:'bookings',label:'Aanvragen',format:'number'},{key:'withCatering',label:'Met catering',format:'number'},{key:'percentage',label:'Catering %',format:'percentage'}]" /><AnalyticsTable caption="Catering per leerlingband" :rows="data.cateringAnalysis.sizeBandBreakdown" :columns="[{key:'label',label:'Leerlingband'},{key:'bookings',label:'Aanvragen',format:'number'},{key:'withCatering',label:'Met catering',format:'number'},{key:'percentage',label:'Catering %',format:'percentage'}]" /></div>
          <p class="admin-analytics-definition">{{ data.cateringAnalysis.definitions.catering }} {{ data.cateringAnalysis.definitions.schoolIdentity }}</p>
        </section>

        <section id="analytics-development" class="admin-analytics-chapter" aria-labelledby="development-title">
          <div class="admin-analytics-chapter__heading"><span>03</span><div><h2 id="development-title">Ontwikkeling</h2><p>Hoe ontwikkelt het onderwijsbezoek zich over de bezoekjaren?</p></div></div>
          <div class="admin-analytics-controls"><AdminSelect v-model="yearMetric" name="analytics-year-metric" label="Metric" :options="yearMetricOptions" :allow-empty="false" /><AdminSelect v-model="yearMix" name="analytics-year-mix" label="Samenstelling" :options="mixOptions" :allow-empty="false" /><AdminSelect :model-value="presentation('development')" name="analytics-year-view" label="Weergave" :options="presentationOptions" :allow-empty="false" @update:model-value="setPresentation('development', $event)" /></div>
          <div class="admin-analytics-visual-grid"><AnalyticsChart v-if="yearChart && presentationIncludes('development','chart')" :config="yearChart" label="Ontwikkeling per bezoekjaar" :visibility-hint="visibleSections.has('development')" /><aside class="admin-analytics-insight"><strong>Managementcontext</strong><p>{{ data.yearlyAnalysis.context }}</p><p>Boekingsstand per {{ new Date(data.yearlyAnalysis.generatedAt).toLocaleString('nl-NL') }}.</p></aside></div>
          <AnalyticsTable v-if="presentationIncludes('development','table')" caption="Jaar-op-jaarvergelijking" :rows="yearRows" :columns="[{key:'year',label:'Jaar',sortType:'number'},{key:'value',label:'Waarde',format:'number'},{key:'difference',label:'Verschil'},{key:'differencePercentage',label:'Verschil %'},{key:'status',label:'Status'}]" />
          <div class="admin-analytics-year-mix"><article v-for="year in data.yearlyAnalysis.years" :key="year.year"><h3>{{ year.year }}</h3><div v-for="item in yearMix === 'program' ? year.programMix : year.sectorMix" :key="item.label"><span>{{ item.label }}</span><strong>{{ nf.format(item.percentage) }}%</strong></div></article></div>
        </section>

        <section id="analytics-season" class="admin-analytics-chapter" aria-labelledby="season-title">
          <div class="admin-analytics-chapter__heading"><span>04</span><div><h2 id="season-title">Seizoen en planning</h2><p>Wanneer zit de drukte in het onderwijsprogramma?</p></div></div>
          <div class="admin-analytics-controls"><AdminSelect v-model="seasonLevel" name="analytics-season-level" label="Weergaveniveau" :options="seasonLevelOptions" :allow-empty="false" /><AdminSelect v-model="seasonMetric" name="analytics-season-metric" label="Intensiteit" :options="seasonMetricOptions" :allow-empty="false" /><AdminSelect :model-value="presentation('season')" name="analytics-season-view" label="Weergavevorm" :options="presentationOptions" :allow-empty="false" @update:model-value="setPresentation('season', $event)" /></div>
          <p class="admin-analytics-definition">{{ seasonLevel === 'monthly' ? 'Welke maanden zijn het drukst binnen de geselecteerde periode?' : 'Welke weekdag is gemiddeld het drukst?' }}</p>
          <div class="admin-analytics-visual-grid"><AnalyticsChart v-if="seasonChart && presentationIncludes('season','chart')" :config="seasonChart" :label="seasonLevel === 'monthly' ? 'Drukte per kalendermaand' : 'Drukte per weekdag'" :visibility-hint="visibleSections.has('season')" /><aside v-if="seasonContext" class="admin-analytics-insight"><strong>{{ seasonLevel === 'monthly' ? 'Maandcontext' : 'Weekdagcontext' }}</strong><dl><div><dt>Drukste {{ seasonLevel === 'monthly' ? 'maand' : 'weekdag' }}</dt><dd>{{ seasonContext.busiestLabel }}</dd></div><div><dt>{{ seasonContext.metricLabel }}</dt><dd>{{ nf.format(seasonContext.metricValue) }}</dd></div><div><dt>Leerlingen</dt><dd>{{ nf.format(seasonContext.students) }}</dd></div><div><dt>Aanvragen</dt><dd>{{ nf.format(seasonContext.bookings) }}</dd></div><div><dt>Bezoekdagen</dt><dd>{{ nf.format(seasonContext.visitDays) }}</dd></div><div v-if="seasonLevel === 'weekday'"><dt>Gem. leerlingen per bezoekdag</dt><dd>{{ nf.format(seasonContext.averageStudents) }}</dd></div><div v-if="seasonLevel === 'weekday'"><dt>Gem. scholen per bezoekdag</dt><dd>{{ nf.format(seasonContext.averageSchools) }}</dd></div></dl><p v-if="seasonLevel === 'weekday'" class="admin-analytics-definition">Bij een gelijke waarde bepalen achtereenvolgens leerlingen, aanvragen en kalenderorde de rangschikking.</p></aside></div>
          <AnalyticsTable v-if="presentationIncludes('season','table') && seasonLevel === 'monthly'" caption="Seizoen per maand" :rows="seasonRows" :emphasized-key="seasonMetric === 'visitDays' ? 'visitDays' : seasonMetric" :columns="[{key:'label',label:'Maand',sortable:false},{key:'bookings',label:'Aanvragen',format:'number'},{key:'visitDays',label:'Bezoekdagen',format:'number'},{key:'schools',label:'Scholen',format:'number'},{key:'students',label:'Leerlingen',format:'number'},{key:'average',label:'Gem. per dag',format:'number'}]" />
          <AnalyticsTable v-else-if="presentationIncludes('season','table')" caption="Seizoen per weekdag" :rows="seasonRows" :emphasized-key="seasonMetric" :columns="[{key:'weekdayLabel',label:'Weekdag',sortable:false},{key:'uniqueVisitDates',label:'Bezoekdagen',format:'number'},{key:'bookings',label:'Aanvragen',format:'number'},{key:'students',label:'Leerlingen',format:'number'},{key:'averageStudentsPerVisitDate',label:'Gem. leerlingen per dag',format:'number'},{key:'averageBookingsPerVisitDate',label:'Gem. aanvragen per dag',format:'number'},{key:'averageSchoolsPerVisitDate',label:'Gem. scholen per dag',format:'number'},{key:'programLabel',label:'Programma’s',sortable:false}]" />
          <p class="admin-analytics-definition">Aanvragen en leerlingen zijn optelbaar. Weekdaggemiddelden en unieke scholen zijn niet optelbaar; zij zijn vanuit concrete bezoekdatums berekend.</p>

          <section class="admin-analytics-season-subsection" aria-labelledby="school-days-title"><div><h3 id="school-days-title">Scholen per bezoekdag</h3><p>Hoe vaak ontvangen we één school en hoe vaak delen twee scholen dezelfde bezoekdag?</p><p class="admin-analytics-definition">Gebaseerd op de geselecteerde statuspopulatie: {{ populationOptions.find((option) => option.value === population)?.label }}.</p></div><template v-if="data.seasonalityAnalysis.schoolOccupancy.totalBookedVisitDates"><div class="admin-analytics-mini-kpis"><div><span>Dagen met 1 school</span><strong>{{ nf.format(data.seasonalityAnalysis.schoolOccupancy.categories[0]?.percentageOfBookedVisitDates ?? 0) }}%</strong></div><div><span>Dagen met 2 scholen</span><strong>{{ nf.format(data.seasonalityAnalysis.schoolOccupancy.categories[1]?.percentageOfBookedVisitDates ?? 0) }}%</strong></div><div><span>Geboekte dagen</span><strong>{{ nf.format(data.seasonalityAnalysis.schoolOccupancy.totalBookedVisitDates) }}</strong></div><div><span>Gem. scholen per dag</span><strong>{{ nf.format(data.seasonalityAnalysis.schoolOccupancy.averageSchoolsPerVisitDate) }}</strong></div><div :class="{ 'is-data-quality': data.seasonalityAnalysis.schoolOccupancy.overCapacityVisitDateCount > 0 }"><span>Dagen boven maximumregel</span><strong>{{ nf.format(data.seasonalityAnalysis.schoolOccupancy.overCapacityVisitDateCount) }}</strong></div></div><AnalyticsChart v-if="occupancyChart" :config="occupancyChart" label="Aandeel geboekte bezoekdagen naar aantal scholen" :visibility-hint="visibleSections.has('season')" /><AnalyticsTable caption="Scholen per geboekte bezoekdag" :rows="[...occupancyRows, occupancyTotals]" :columns="[{key:'label',label:'Scholen op een dag',sortable:false},{key:'visitDateCount',label:'Bezoekdagen',format:'number',sortable:false},{key:'percentage',label:'Percentage',format:'percentage',sortable:false},{key:'bookings',label:'Aanvragen',format:'number',sortable:false},{key:'students',label:'Leerlingen',format:'number',sortable:false},{key:'averageStudents',label:'Gem. leerlingen per dag',format:'number',sortable:false}]" /><p v-if="data.seasonalityAnalysis.schoolOccupancy.overCapacityVisitDateCount" class="admin-analytics-warning">Dagen met drie of meer scholen wijken af van de huidige maximumregel en blijven zichtbaar als historische dataqualitycontext.</p></template><p v-else class="admin-analytics-empty">Binnen de actuele globale selectie zijn geen geboekte bezoekdagen.</p></section>

          <section class="admin-analytics-top-days" aria-labelledby="top-days-title"><h3 id="top-days-title">Top 10 drukste bezoekdatums</h3><p>Welke afzonderlijke bezoekdatums hebben de hoogste belasting?</p><div class="admin-analytics-visual-grid"><AnalyticsChart v-if="topDaysChart" :config="topDaysChart" label="Gerangschikte top 10 concrete bezoekdatums" :visibility-hint="visibleSections.has('season')" /><AnalyticsTable caption="Top 10 drukste bezoekdatums" :rows="rankedTopDays" emphasized-key="students" :columns="[{key:'rank',label:'#',format:'number',sortable:false},{key:'date',label:'Datum',format:'date',sortable:false},{key:'weekday',label:'Weekdag',sortable:false},{key:'bookings',label:'Aanvragen',format:'number',sortable:false},{key:'uniqueSchools',label:'Scholen',format:'number',sortable:false},{key:'students',label:'Leerlingen',format:'number',sortable:false}]" /></div></section>
        </section>

        <section class="admin-analytics-chapter is-visible" aria-labelledby="detail-tables-title">
          <div class="admin-analytics-chapter__heading"><span>Details</span><div><h2 id="detail-tables-title">Bestaande managementtabellen</h2><p>Exacte verdelingen blijven beschikbaar als volwaardige analysemethode.</p></div></div>
          <div class="admin-analytics-grid">
            <section class="admin-analytics-section admin-analytics-section--wide"><h3>Ontwikkeling per maand</h3><AnalyticsTable caption="Ontwikkeling per maand" :rows="data.monthlyTrend" :columns="[{key:'month',label:'Maand',sortType:'month'},{key:'activeBookings',label:'Actief',format:'number'},{key:'confirmedBookings',label:'Definitief',format:'number'},{key:'plannedStudents',label:'Leerlingen',format:'number'},{key:'uniqueSchools',label:'Scholen',format:'number'},{key:'uniqueVisitDays',label:'Bezoekdagen',format:'number'},{key:'averageStudents',label:'Gem.',format:'number'}]" :filters="[{key:'month',label:'Maand of jaar zoeken',type:'search'},{key:'plannedStudents',label:'Minimum leerlingen',type:'minimum'}]" /></section>
            <section class="admin-analytics-section"><h3>Onderwijsverdeling</h3><AnalyticsTable caption="Sectorverdeling" :rows="data.sectorDistribution" :columns="[{key:'label',label:'Sector',sortable:false},{key:'activeBookings',label:'Aanvragen',format:'number'},{key:'plannedStudents',label:'Leerlingen',format:'number'},{key:'bookingShare',label:'Aandeel aanvragen',format:'percentage'},{key:'studentShare',label:'Aandeel leerlingen',format:'percentage'},{key:'averageStudents',label:'Gemiddeld',format:'number'}]" /></section>
            <section class="admin-analytics-section"><h3>Programma’s</h3><AnalyticsTable caption="Programmaverdeling" :rows="data.programDistribution" :columns="[{key:'label',label:'Programma',sortable:false},{key:'activeBookings',label:'Aanvragen',format:'number'},{key:'plannedStudents',label:'Leerlingen',format:'number'},{key:'bookingShare',label:'Aandeel',format:'percentage'},{key:'averageStudents',label:'Gemiddeld',format:'number'},{key:'capacityUtilization',label:'Capaciteitsbenutting',format:'percentage'}]" /></section>
            <section class="admin-analytics-section"><h3>Keuzemodules</h3><AnalyticsTable caption="Keuzemodules" :rows="data.choiceModuleDistribution" :columns="[{key:'label',label:'Module'},{key:'activeBookings',label:'Aanvragen',format:'number'},{key:'students',label:'Leerlingen',format:'number'},{key:'populationShare',label:'Aandeel',format:'percentage'}]" /></section>
            <section class="admin-analytics-section"><h3>Groepssamenstelling</h3><dl class="admin-analytics-composition"><div><dt>VO: één niveau</dt><dd>{{ data.compositionDistribution.oneLevel }}</dd></div><div><dt>VO: meerdere niveaus</dt><dd>{{ data.compositionDistribution.multipleLevels }}</dd></div><div><dt>Eén groep</dt><dd>{{ data.compositionDistribution.oneGroup }}</dd></div><div><dt>Twee groepen</dt><dd>{{ data.compositionDistribution.twoGroups }}</dd></div><div><dt>Drie of meer groepen</dt><dd>{{ data.compositionDistribution.threeOrMoreGroups }}</dd></div></dl></section>
            <section class="admin-analytics-section"><h3>Planning en spreiding</h3><AnalyticsTable caption="Verdeling per weekdag" :rows="data.weekdayDistribution" :columns="[{key:'weekday',label:'Weekdag'},{key:'uniqueVisitDays',label:'Bezoekdagen',format:'number'},{key:'activeBookings',label:'Aanvragen',format:'number'},{key:'plannedStudents',label:'Leerlingen',format:'number'},{key:'averageStudentsPerVisitDay',label:'Gem. per bezoekdag',format:'number'}]" /></section>
          </div>
        </section>
      </template>
    </template>
  </section>
</template>
