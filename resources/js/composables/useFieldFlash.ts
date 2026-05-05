import { ref, watch, type Ref, onBeforeUnmount, computed } from 'vue';
import type { ValidationShape, Issue } from '../types/validation/booking';

export type ErrorBehavior = "auto" | "persistent";

type Params = {
    issue: Ref<ValidationShape | undefined>;
    trigger: Ref<number>;
    behavior: Ref<ErrorBehavior>;
    autoDismissMs: Ref<number>;
}

export function useFieldFlash({
    issue,
    trigger,
    behavior,
    autoDismissMs
}: Params) {
    const visible = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;

    const cleartimer = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }
    //msg is what is called a reactive derived value
    //vue automatically updates the value if one of dependencies changes

    const msg = computed<Issue>(() => {
        const i = issue.value
        if (!i) return null;
        return i.error ?? i.warning ?? null;
    })

    const hide = () => {
        cleartimer();
        visible.value = false
    }

    const showAuto = () => {
        cleartimer();
        visible.value = true;
    
        const ms = autoDismissMs.value;
        if (ms > 0) {
            timer = setTimeout(() => {
                visible.value = false;
                timer = null;
            }, ms);
        }
    }

    watch([msg, trigger, behavior], ([msg]) => {
        const mode: ErrorBehavior = behavior.value;

        if (!msg || msg.length == 0){
            hide();
            return;
        }

        if (mode === "persistent"){
            cleartimer();
            visible.value = true;
            return;
        }

        showAuto();
    }, {immediate: true});

    onBeforeUnmount(() => {
        cleartimer();
    })

    return { visible, hide, msg }
}
 
/*
  About Vue's `watch` with multiple sources:

  watch([trigger1, trigger2], ([new1, new2], [old1, old2]) => {
    // new1 / old1  -> correspond to trigger1
    // new2 / old2  -> correspond to trigger2
  })

  - Vue triggers the watcher whenever *any* of the watched sources change.
  - Both newValues[] and oldValues[] are arrays matching the same order
    as the watched sources — this mapping is **position-based**, not name-based.
  - Even if only one source changes, Vue still passes the full arrays of
    current and previous values for all watched sources.
  - Use array destructuring to access only the sources you need:
      e.g. ([new1]) => {...}  // only cares about the first source
*/