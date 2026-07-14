# Localization Guide

## Finding (2026-07-14 best-practice audit)

No `lang/` directory; only 11 of 459 Blade views used `__()`/`@lang` at
all (all from Laravel Breeze's own scaffold — `auth/*`, `dashboard.blade.php`,
`profile/*`) — meaning the calls existed but had nothing to translate
against, since `lang/en/*.php` had never been published. `<html lang="...">`
was already dynamic, so the wiring for a working locale system existed;
it just had no locale files and no way for a request/user to pick a
non-default locale.

## Scope decision

Extracting all hardcoded strings across ~450 remaining views is large,
dedicated-project scope — the same reasoning as `A11Y_ROADMAP.md`. This
phase builds the **framework** (locale resolution, storage, published
default language files) and converts **one full view** (the login page,
plus the two shared layouts' skip-link text) as a working, tested
proof-of-pattern, rather than a partial, unverified sweep across many
views.

## What's done

- **`php artisan lang:publish`** run once, publishing Laravel's own
  built-in `lang/en/{auth,pagination,passwords,validation}.php` — these
  back the framework's own validation/auth messages, which the Breeze
  scaffold views already call via `__()`; before this they silently had
  nothing to translate against.
- **`lang/id/auth.php`** — a real Indonesian translation of the 3 auth
  message keys (`failed`, `password`, `throttle`).
- **`lang/id.json`** — Laravel's JSON string-key convention (the format
  the *existing* Breeze `__('Log in')`-style calls already use, so this
  stays consistent with what was there rather than introducing a second,
  competing convention) — real Indonesian translations for every
  customer-facing string on the login page and the shared skip-link text.
- **`app/Http/Middleware/SetUserLocale`** (registered in the `web`
  middleware group): resolves the active locale per request, in order —
  `?lang=xx` query override (persisted to session + the user's own
  `locale` column if authenticated) → the user's stored preference →
  session → `config('app.locale')` fallback. `SetUserLocale::SUPPORTED_LOCALES`
  is the single place to add a new locale code.
- **`users.locale`** column (nullable — `null` means "no preference set",
  not broken) + a **Language** selector on the profile settings page
  (`resources/views/profile/partials/update-profile-information-form.blade.php`),
  wired through the existing `ProfileController::update()`/
  `ProfileUpdateRequest` without needing a new controller action.
- 9 new tests (`tests/Feature/I18nLocalizationTest.php`).

## How to add a new locale

1. Add the language code to `App\Http\Middleware\SetUserLocale::SUPPORTED_LOCALES`.
2. Add `lang/{code}.json` with translations for every English string key
   already used across the app (start from `lang/id.json` as a template —
   it's intentionally small right now, covering only the login page and
   shared layout strings).
3. Optionally add `lang/{code}/auth.php`, `pagination.php`, `passwords.php`,
   `validation.php` (copy `lang/en/*.php` and translate) if you want
   framework-level messages (validation errors, pagination "Next"/
   "Previous", etc.) in that language too — until then, Laravel's
   `fallback_locale` (`en`, `config/app.php`) means those messages
   degrade gracefully to English rather than breaking.
4. Add the code as an `<option>` in the profile settings Language select
   (currently generated from `SUPPORTED_LOCALES` directly, so this step
   is often already covered by step 1).

## Explicitly NOT done — separate, future scope

- **String extraction across the other ~455 views** — the vast majority
  of the UI is still hardcoded English text with no `__()` wrapping at
  all. This phase proves the pattern works end-to-end (resolution,
  storage, real translated output); applying it view-by-view across the
  rest of the SOC console is real, separate, incremental work.
- **`lang/id/{pagination,passwords,validation}.php`** — Laravel's larger
  built-in message files (100+ keys for `validation.php` alone) were
  deliberately not translated in this pass; English fallback via
  `fallback_locale` is graceful, not broken, in the meantime.
- **RTL layout support** — not needed for `en`/`id` (both LTR); would be
  a separate concern if a right-to-left locale is ever added.
- **Locale-aware date/number formatting** — Carbon's own locale support
  (`Carbon::setLocale()`) isn't wired to `SetUserLocale` yet; dates
  currently render in a fixed format regardless of the active locale.
