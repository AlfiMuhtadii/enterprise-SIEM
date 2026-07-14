# Accessibility (WCAG 2.1 AA) Roadmap

## Finding (2026-07-14 best-practice audit)

Only 1 of 459 Blade views used any `aria-*` attribute, zero `<img alt>`,
no focus management on modals/menus, tables lacked `scope`/caption. For
international/government procurement this is a hard blocker
(Section 508 / EN 301 549 both reference WCAG 2.1 AA).

## Scope decision

Full remediation across 459 views is large, dedicated-project scope, not
something to attempt in a single pass without either taking weeks or
doing a superficial, unverified job across most of the surface. This
first phase deliberately targets the **shared layout and component
files** instead of individual pages — every page that extends
`layouts.app` or `layouts.guest`, or uses `<x-nav-link>`/
`<x-application-logo>`/`<x-input-error>`, inherits the fix automatically,
which is a much higher-leverage starting point than fixing 1-2 pages in
isolation.

## Done in this phase

- **Skip link** (`layouts/app.blade.php`, `layouts/guest.blade.php`) —
  visually hidden until focused, targets `#main-content`. Every page
  using either layout now has one.
- **`<main id="main-content">` landmark** on both layouts (the app
  layout already had a bare `<main>`; the guest/login layout didn't have
  any landmark at all).
- **Sidebar/nav labelling** (`layouts/navigation.blade.php`): `<aside
  aria-label="Sidebar">`, `<nav aria-label="Primary">`.
- **Mobile menu disclosure semantics** (`layouts/app.blade.php`): the
  toggle button now has `aria-controls="sidebar-nav"` and a live
  `:aria-expanded` binding; the drawer's backdrop is `aria-hidden`; the
  whole `mobileOpen` state now closes on `Escape` (`@keydown.escape.window`)
  — previously the only way to close it was clicking the backdrop.
- **Decorative icons hidden from assistive tech**: the inline SVG logo
  (`components/application-logo.blade.php`) and every sidebar nav-link
  icon (`components/nav-link.blade.php`) now carry `aria-hidden="true"`
  — they're always paired with visible text, so exposing them to screen
  readers was pure noise, not information.
- **Active nav-link state exposed programmatically**
  (`components/nav-link.blade.php`): `aria-current="page"` when active,
  omitted otherwise — previously only conveyed via CSS class/color.
- **Form-error announcement** (`components/input-error.blade.php`):
  `role="alert"` on the error list, so validation errors are announced
  to screen-reader users when they appear, not just visually shown.
- **Full proof-of-pattern on `resources/views/auth/login.blade.php`**:
  the email/password fields now set `aria-invalid="true"` and
  `aria-describedby` pointing at the matching `id="{field}-error"` error
  list only when that field actually has a validation error — the
  concrete example for wiring the same pattern into other forms.
- 6 new tests (`tests/Feature/A11yWcagTest.php`), full regression run
  clean (see `REVIEW_COMPLETED.md` for the exact count).

## Explicitly NOT done — separate, future scope

- **Per-view remediation of the other ~455 Blade views** — most still
  have no ARIA at all. The shared-layout fix above benefits every page
  structurally, but page-specific content (data tables, custom
  dropdowns/modals inside individual SOC screens, chart/graph
  visualizations) needs its own pass.
- **Table accessibility** (`scope="col"`, `<caption>`) — no shared table
  component exists to fix once; every `<table>` across the 459 views
  needs individual attention.
- **Custom modal/dialog components elsewhere in the app** (outside the
  mobile sidebar drawer fixed here) — full focus-trap (focus moves into
  the dialog on open, returns to the trigger on close, Tab is contained
  within it while open) needs auditing per component.
- **Automated CI gate** (axe-core/pa11y as a required check) — the
  finding's own proposed fix asks for this; it needs real browser
  automation (Playwright/Puppeteer + axe-core) that doesn't exist in
  this repo yet, and wiring it into `.github/workflows/ci.yml` hits the
  same active-concurrent-restructuring collision noted in
  `REVIEW_COMPLETED.md`'s `CI-SAST-DEPSCAN`/`QA-STATIC-ANALYSIS` entries.
  Both — building the harness and wiring the gate — are real, separate
  follow-on work.
- **Full WCAG 2.1 AA conformance audit** (color contrast ratios, form
  field grouping/`fieldset`/`legend`, live-region announcements for
  async SOC data updates, keyboard-only walkthrough of every interactive
  widget) — this document tracks structural/landmark progress, not a
  conformance certification.
