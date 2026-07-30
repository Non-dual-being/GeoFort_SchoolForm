export type DisabledDateType = "manual" | "school_vacation" | "weekend";
export type ManageableDisabledDateType = Exclude<DisabledDateType, "weekend">;

export const disabledDateLabels: Readonly<Record<DisabledDateType, string>> = {
  weekend: "Weekend",
  school_vacation: "Vakantie",
  manual: "Niet beschikbaar",
};

export function isDisabledDateType(value: unknown): value is DisabledDateType {
  return value === "manual" || value === "school_vacation" || value === "weekend";
}

export function disabledDateLabel(type: DisabledDateType): string {
  return disabledDateLabels[type];
}
