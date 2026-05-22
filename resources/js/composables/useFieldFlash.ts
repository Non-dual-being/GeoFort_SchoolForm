import { computed, onBeforeUnmount, ref, watch, type Ref } from "vue";
import { Issue, ValidationShape } from "../types/validation/FieldErrorTypes";
export type ErrorBehavior = "auto" | "persistent";

type Params = {
  issue: Ref<ValidationShape | undefined>;
  trigger: Ref<number>;
  behavior: Ref<ErrorBehavior>;
  autoDismissMs: Ref<number>;
};

export function useFieldFlash({
  issue,
  trigger,
  behavior,
  autoDismissMs,
}: Params) {
  const visible = ref(false);
  let timer: ReturnType<typeof setTimeout> | null = null;

  const clearTimer = () => {
    if (!timer) {
      return;
    }

    clearTimeout(timer);
    timer = null;
  };

  const msg = computed<Issue>(() => {
    const currentIssue = issue.value;

    if (!currentIssue) {
      return null;
    }

    return currentIssue.error ?? currentIssue.warning ?? null;
  });

  const hide = () => {
    clearTimer();
    visible.value = false;
  };

  const showAuto = () => {
    clearTimer();
    visible.value = true;

    const ms = autoDismissMs.value;

    if (ms > 0) {
      timer = setTimeout(() => {
        visible.value = false;
        timer = null;
      }, ms);
    }
  };

  watch(
    [msg, trigger, behavior],
    ([currentMessage]) => {
      const mode = behavior.value;

      if (!currentMessage || currentMessage.length === 0) {
        hide();
        return;
      }

      if (mode === "persistent") {
        clearTimer();
        visible.value = true;
        return;
      }

      showAuto();
    },
    {
      immediate: true,
    },
  );

  onBeforeUnmount(() => {
    clearTimer();
  });

  return {
    visible,
    hide,
    msg,
  };
}