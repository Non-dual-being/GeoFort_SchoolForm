import type { ChartConfiguration } from "chart.js";
import type { CapacityMonthRow, CateringAnalysis, MonthlyBucket, SchoolOccupancyAnalysis, SeasonMetric, StudentCountAnalysis, TopDay, WeekdayBucket, YearlyAnalysis, YearMetric } from "../types/bookingAnalytics";
import type { BookingAnalyticsResponse } from "../types/bookingAnalytics";

const colors = { day: "#0b3a78", morning: "#25a9c5", catering: "#168c8c", neutral: "#8ba0b7", amber: "#c78313" };
const base = { responsive: true, maintainAspectRatio: false, animation: { duration: 450 }, plugins: { legend: { position: "bottom" as const, labels: { usePointStyle: true } } } };

export function buildStudentChart(analysis: StudentCountAnalysis, mode: "students" | "capacity", display: "count" | "percentage"): ChartConfiguration<"bar"> {
  const bins = mode === "students" ? analysis.bins : analysis.capacityBins;
  return { type: "bar", data: { labels: bins.map((bin) => bin.label), datasets: analysis.programs.map((program) => ({ label: program.label, data: (mode === "students" ? program.studentBins : program.capacityBins).map((bin) => display === "count" ? bin.bookings : bin.percentage), backgroundColor: program.key === "dag" ? colors.day : colors.morning, borderRadius: 5 })) }, options: { ...base, plugins: { ...base.plugins, tooltip: { callbacks: { afterLabel: (context) => { const program = analysis.programs[context.datasetIndex]; const row = (mode === "students" ? program?.studentBins : program?.capacityBins)?.[context.dataIndex]; return row && program ? [`${row.bookings} aanvragen (${row.percentage.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}%)`, `${row.students} leerlingen · gem. ${(row.averageStudents ?? 0).toLocaleString("nl-NL", { maximumFractionDigits: 1 })}`, `Noemer: ${program.denominator} ${program.label.toLowerCase()}-aanvragen`] : []; } } } }, scales: { y: { beginAtZero: true, ticks: { callback: (value) => display === "percentage" ? `${value}%` : String(value) } } } } };
}

export function buildCateringChart(analysis: CateringAnalysis, mode: "school" | "booking"): ChartConfiguration<"doughnut"> {
  const rows = mode === "school" ? analysis.schoolProfiles : analysis.bookingProfiles;
  return { type: "doughnut", data: { labels: rows.map((row) => row.label), datasets: [{ data: rows.map((row) => row.count), backgroundColor: [colors.catering, colors.morning, colors.neutral, colors.day, "#6f79b8", colors.amber] }] }, options: { ...base, cutout: "58%", plugins: { ...base.plugins, tooltip: { callbacks: { afterLabel: (context) => { const row = rows[context.dataIndex]; return row ? `${row.percentage.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}% van de relevante populatie` : ""; } } } } } };
}

export function buildYearChart(analysis: YearlyAnalysis, metric: YearMetric): ChartConfiguration<"line"> {
  return { type: "line", data: { labels: analysis.years.map((row) => String(row.year)), datasets: [{ label: analysis.availableMetrics[metric], data: analysis.years.map((row) => row.metrics[metric]), borderColor: colors.day, backgroundColor: "rgba(37,169,197,.16)", fill: true, tension: 0.25, pointRadius: 5 }] }, options: { ...base, scales: { y: { beginAtZero: true } } } };
}

export function buildSeasonChart(rows: MonthlyBucket[] | WeekdayBucket[], level: "monthly" | "weekday", metric: SeasonMetric): ChartConfiguration<"bar"> {
  return { type: "bar", data: { labels: rows.map((row) => level === "monthly" ? (row as MonthlyBucket).label : (row as WeekdayBucket).weekdayLabel), datasets: [{ label: metricLabel(metric), data: rows.map((row) => metric === "visitDays" ? (row as MonthlyBucket).uniqueVisitDates : Number(row[metric as keyof typeof row])), backgroundColor: colors.day, borderRadius: 5 }] }, options: { ...base, indexAxis: "x", plugins: { ...base.plugins, legend: { display: false }, tooltip: { callbacks: { label: (context) => seasonTooltip(rows, level, metric, context.dataIndex) } } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } } };
}

export function buildSchoolOccupancyChart(analysis: SchoolOccupancyAnalysis): ChartConfiguration<"bar"> {
  return { type: "bar", data: { labels: ["Geboekte bezoekdagen"], datasets: analysis.categories.map((row, index) => ({ label: row.label, data: [row.percentageOfBookedVisitDates], backgroundColor: ["#78a9dd", colors.morning, colors.amber, colors.neutral][index], borderWidth: row.isDataQualityCategory ? 2 : 0, borderColor: row.isDataQualityCategory ? "#8a5a0a" : "transparent" })) }, options: { ...base, indexAxis: "y", scales: { x: { stacked: true, beginAtZero: true, max: 100, ticks: { callback: (value) => `${value}%` } }, y: { stacked: true, grid: { display: false } } }, plugins: { ...base.plugins, tooltip: { callbacks: { label: (context) => { const row = analysis.categories[context.datasetIndex]; return row ? `${row.label}: ${row.visitDateCount.toLocaleString("nl-NL")} dagen (${row.percentageOfBookedVisitDates.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}%)` : ""; }, afterLabel: (context) => { const row = analysis.categories[context.datasetIndex]; return row ? [`${row.bookingCount.toLocaleString("nl-NL")} aanvragen`, `${row.studentCount.toLocaleString("nl-NL")} leerlingen`] : []; } } } } } };
}

export function buildTopDaysChart(rows: TopDay[], metric: "students" | "bookings"): ChartConfiguration<"bar"> {
  return { type: "bar", data: { labels: rows.map((row) => `#${row.rank} ${row.weekday} ${row.date}`), datasets: [{ label: metricLabel(metric), data: rows.map((row) => row[metric]), backgroundColor: colors.morning, borderRadius: 5 }] }, options: { ...base, indexAxis: "y", plugins: { ...base.plugins, legend: { display: false } }, scales: { x: { beginAtZero: true }, y: { grid: { display: false } } } } };
}

function seasonTooltip(rows: MonthlyBucket[] | WeekdayBucket[], level: "monthly" | "weekday", metric: SeasonMetric, index: number): string[] {
  const row = rows[index];
  if (!row) return [];
  if (level === "monthly") { const month = row as MonthlyBucket; return [month.label, `${month.bookings.toLocaleString("nl-NL")} aanvragen · ${month.uniqueVisitDates.toLocaleString("nl-NL")} bezoekdagen · ${month.uniqueSchools.toLocaleString("nl-NL")} scholen`, `${month.students.toLocaleString("nl-NL")} leerlingen · gem. ${month.averageStudentsPerVisitDate.toLocaleString("nl-NL")} per bezoekdag`]; }
  const day = row as WeekdayBucket;
  return [day.weekdayLabel, `${metricLabel(metric)}: ${Number(day[metric as keyof WeekdayBucket]).toLocaleString("nl-NL", { maximumFractionDigits: 1 })}`, `Aanvragen: ${day.bookings.toLocaleString("nl-NL")}`, `Unieke bezoekdatums: ${day.uniqueVisitDates.toLocaleString("nl-NL")}`, `Leerlingen: ${day.students.toLocaleString("nl-NL")}`, `Gem. leerlingen per bezoekdag: ${day.averageStudentsPerVisitDate.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}`, `Gem. aanvragen per bezoekdag: ${day.averageBookingsPerVisitDate.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}`, `Gem. scholen per bezoekdag: ${day.averageSchoolsPerVisitDate.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}`, `Dagprogramma-aanvragen: ${day.dayProgramBookings.toLocaleString("nl-NL")}`, `Ochtendprogramma-aanvragen: ${day.morningProgramBookings.toLocaleString("nl-NL")}`];
}

function metricLabel(metric: SeasonMetric): string { return ({ students: "Leerlingen", bookings: "Aanvragen", visitDays: "Unieke bezoekdagen", averageStudentsPerVisitDate: "Gemiddeld leerlingen per bezoekdag", averageBookingsPerVisitDate: "Gemiddeld aanvragen per bezoekdag", averageSchoolsPerVisitDate: "Gemiddeld scholen per bezoekdag" } as Record<SeasonMetric, string>)[metric]; }

export function reducedMotionConfig<T extends ChartConfiguration>(config: T, reduced: boolean): T { return reduced ? { ...config, options: { ...config.options, animation: false } } : config; }

export function buildNewSchoolsChart(rows: BookingAnalyticsResponse["newSchoolsByMonth"]): ChartConfiguration<"bar"> {
  return { type: "bar", data: { labels: rows.map(row => row.label), datasets: [{ label: "Nieuwe scholen", data: rows.map(row => row.count), backgroundColor: colors.morning, borderRadius: 5 }] }, options: { ...base, plugins: { ...base.plugins, legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } };
}

export function buildCapacityChart(rows: BookingAnalyticsResponse["capacityByMonth"]): ChartConfiguration<"bar"> {
  return { type: "bar", data: { labels: rows.map(row => row.label), datasets: [
    { label: "Leerlingcapaciteit benut", data: rows.map(row => row.students.percentage), backgroundColor: rows.map(row => (row.students.percentage ?? 0) > 100 ? colors.amber : colors.day), borderRadius: 5 },
    { label: "Boekingsplekken benut", data: rows.map(row => row.bookingSlots.percentage), backgroundColor: rows.map(row => (row.bookingSlots.percentage ?? 0) > 100 ? colors.amber : colors.morning), borderRadius: 5 },
  ] }, options: { ...base, plugins: { ...base.plugins, legend: { ...base.plugins.legend, align: "start" }, tooltip: { callbacks: { afterLabel: context => { const row = rows[context.dataIndex]; if (!row) return []; const value = context.datasetIndex === 0 ? row.students : row.bookingSlots; return value.percentage === null ? ["Geen beschikbare dagen"] : [`${value.actual.toLocaleString("nl-NL")} van ${value.capacity.toLocaleString("nl-NL")}`, `${value.percentage.toLocaleString("nl-NL", { maximumFractionDigits: 1 })}%`]; } } } }, scales: { y: { beginAtZero: true, ticks: { callback: value => `${value}%` } }, x: { grid: { display: false } } } } };
}

export function buildCapacityTargetChart(rows: CapacityMonthRow[], metric: "students" | "bookings" | "average"): ChartConfiguration {
  const comparison = (row: CapacityMonthRow) => metric === "students" ? row.studentsTargetComparison : metric === "bookings" ? row.bookingsTargetComparison : row.averageBookingSizeComparison;
  const actualLabel = metric === "students" ? "Werkelijk leerlingen" : metric === "bookings" ? "Werkelijk boekingen" : "Werkelijke gemiddelde boekingsgrootte";
  const targetLabel = metric === "students" ? "Officieel leerlingtarget" : metric === "bookings" ? "Officieel boekingtarget" : "Afgeleid target";
  const datasets: Array<Record<string, unknown>> = [
    { type: "bar", label: actualLabel, data: rows.map(row => comparison(row).actual), backgroundColor: rows.map(row => comparison(row).aboveTechnicalCapacity ? colors.amber : colors.day), borderRadius: 5, order: 3 },
    { type: "line", label: targetLabel, data: rows.map(row => comparison(row).target), borderColor: colors.morning, backgroundColor: colors.morning, borderWidth: 3, pointRadius: 4, spanGaps: false, order: 1 },
  ];
  if (metric !== "average") datasets.push({ type: "line", label: "Technische capaciteit", data: rows.map(row => metric === "students" ? row.technicalCapacity.students : row.technicalCapacity.bookings), borderColor: colors.neutral, backgroundColor: colors.neutral, borderDash: [6, 5], borderWidth: 2, pointRadius: 0, order: 2 });
  return { type: "bar", data: { labels: rows.map(row => row.label), datasets: datasets as never }, options: { ...base, plugins: { ...base.plugins, tooltip: { callbacks: { afterBody: items => { const index = items[0]?.dataIndex; const row = index === undefined ? undefined : rows[index]; const value = row ? comparison(row) : null; if (!row || !value) return []; if (row.periodState === "future") return ["Toekomstige periode: nog geen targetoordeel."]; if (value.target === null) return ["Geen officieel target voor deze periode."]; const difference = value.difference ?? 0; return [`Verschil: ${Math.abs(difference).toLocaleString("nl-NL", { maximumFractionDigits: 1 })} ${difference < 0 ? "onder" : difference > 0 ? "boven" : "op"} target`, value.differencePercentage === null ? "Percentage niet beschikbaar" : `${Math.abs(value.differencePercentage).toLocaleString("nl-NL", { maximumFractionDigits: 1 })}% ${difference < 0 ? "onder" : difference > 0 ? "boven" : "op"} target`]; } } } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } } };
}
