<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { RosterStaffPreference } from "../../types/rosterStaff";

const props = defineProps<{ preferences: RosterStaffPreference[]; memberName: string }>();
const list = ref<HTMLOListElement | null>(null);
const rowHeight = ref<number>();
const viewportHeight = ref<number>();
const scrollable = computed(() => props.preferences.length > 5);
const sorted = computed(() => [...props.preferences].sort((a, b) => a.rank - b.rank || a.moduleLabel.localeCompare(b.moduleLabel, "nl")));
let observer: ResizeObserver | undefined;

function measure(): void {
  if (!list.value || !scrollable.value) { rowHeight.value = undefined; viewportHeight.value = undefined; return; }
  // Equal natural row heights accommodate wrapped names. The viewport contains exactly five rows.
  const rows = [...list.value.children] as HTMLLIElement[];
  const height = Math.ceil(Math.max(...rows.map(row => {
    const style = getComputedStyle(row);
    return Math.max(...[...row.children].map(child => child.getBoundingClientRect().height))
      + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
  })));
  rowHeight.value = height;
  viewportHeight.value = height * 5 + parseFloat(getComputedStyle(list.value).rowGap) * 4;
}
async function observe(): Promise<void> {
  await nextTick();
  observer?.disconnect();
  if (!list.value) return;
  observer = new ResizeObserver(measure);
  observer.observe(list.value);
  list.value.querySelectorAll("li > span").forEach(label => observer?.observe(label));
  measure();
}
onMounted(observe);
watch(() => props.preferences, observe, { deep: true });
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
  <div
    class="admin-roster-staff-config__module-window"
    :class="{ 'is-scrollable': scrollable }"
    :style="scrollable && viewportHeight ? { height: viewportHeight + 'px', '--staff-module-row-height': rowHeight + 'px' } : undefined"
    :tabindex="scrollable ? 0 : undefined"
    :role="scrollable ? 'region' : undefined"
    :aria-label="scrollable ? 'Modulevaardigheden van ' + memberName + '; scroll voor meer modules' : undefined"
  >
    <ol ref="list" class="admin-roster-staff-config__preferences">
      <li v-for="preference in sorted" :key="preference.moduleKey">
        <strong>{{ preference.rank }}</strong>
        <span>{{ preference.moduleLabel }}<small v-if="preference.isLegacy">Legacy / onbekend</small></span>
      </li>
    </ol>
  </div>
</template>
