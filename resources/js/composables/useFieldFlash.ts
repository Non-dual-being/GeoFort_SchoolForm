import { computed, onBeforeUnmount, ref, watch, type Ref } from "vue";
import { Issue, ValidationShape } from "../types/validation/FieldErrorTypes";
export type ErrorBehavior = "auto" | "persistent";

type BaseParams = {
  issue: Ref<ValidationShape | undefined>;
  trigger: Ref<number>;
}

type AutoParams = BaseParams & {
  behavior: Ref<"auto">;
  autoDismissMs: Ref<number>;
}

type PersistentParams = BaseParams & {
  behavior: Ref<"persistent">;
  autoDismissMs?: never;
};


type DynamicParams = BaseParams & {
  behavior: Ref<ErrorBehavior>,
  autoDismissMs: Ref<number>
}

type Params = AutoParams | PersistentParams | DynamicParams;

type AutoCapableParams = AutoParams | DynamicParams;

function isAutoCapableParams (params: Params): params is AutoCapableParams {
  return "autoDismissMs" in params && params.autoDismissMs !== undefined;
}

export function useFieldFlash(params: Params) {
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
    const currentIssue = params.issue.value;

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

    if (!isAutoCapableParams(params)) return;

    const ms = params.autoDismissMs.value;

    if (ms > 0) {
      timer = setTimeout(() => {
        visible.value = false;
        timer = null;
      }, ms);
    }
  };

  watch(
    [msg, params.trigger, params.behavior],
    ([currentMessage]) => {
      const mode = params.behavior.value;

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