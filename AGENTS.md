# Project Instructions for Codex

## Working Style

- Work small and focused.
- Make a plan before changing code.
- Touch only files that are necessary for the requested feature.
- Do not do large refactors without explicit permission.
- After finishing, always report changed files and test steps.

## Frontend

- Preserve the existing Vue 3 + TypeScript Composition API style.
- Backend configuration remains the source of truth.
- The frontend may pre-filter options and explain choices, but the backend performs definitive validation.
- Custom flow fields such as `programma`, `educationSelection`, `keuzemodule`, `aantalLeerlingen`, and later `aantalBegeleiders` do not automatically belong in `bookingFieldNames`.
- Use the same visual style as `GeoFormStudentCountField.vue` for comparable new form steps.
- Use existing CSS tokens and the existing class name structure.

## Backend

- Preserve existing PHP `strict_types`, namespaces, and readonly constructor style.
- Keep backend validation authoritative.

## Scope Boundaries

- Do not adjust food/drink, price quotation, schedule download, or terms/conditions behavior unless explicitly requested.
