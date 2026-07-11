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

## Admin frontend

- The admin dashboard is a separate Vue 3 + TypeScript app; the login page remains server-rendered PHP.
- `resources/js/admin.ts` is the admin Vite entrypoint and the dashboard uses Composition API with `<script setup lang="ts">`.
- Vue Router uses hash history. Header, navigation, content, and footer are Vue components.
- `resources/css/admin.css` is only the central import file; layered admin styles belong in `resources/css/admin/` and reuse shared tokens and typography.
- Do not add Pinia without a demonstrated need and never enforce business rules only in Vue; the backend remains authoritative.
- Dashboard PHP entrypoints stay thin and dashboard controllers are injected by `bootstrap.php`.
- API endpoints contain no SQL. Mutations require a private session and action-specific CSRF; future Vue API tokens are sent as headers and validated server-side with `hash_equals`.
- Logout remains a classic POST with its own CSRF token; login and logout tokens are not generic API tokens.
- Temporary success messages dismiss automatically. Errors, lockouts, and session-expiry messages remain visible.
- Public links opened in a new tab use `target="_blank"` with `rel="noopener noreferrer"`.
- Dashboard styling is primarily blue and white; red is only for errors.
- Admin changes must not affect the public booking app.
- Future read endpoints follow `public/api/admin/requests/{week,options,show}.php`; mutations use focused endpoints such as `update-status.php`, `update-student-count.php`, and `calendar/block-date.php`.

## Admin backend

- Controllers parse HTTP requests and map responses.
- DTOs validate and normalize input; services execute use-cases, business rules, and transactions.
- SQL services or repositories perform prepared database reads and writes.
- Views and templates contain no business logic.
- Do not create a monolithic dashboard index or a separate full PHP page for each small action.
