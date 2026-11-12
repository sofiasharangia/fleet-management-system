# Build verification

Verified on November 12, 2026 using PHP 8.4.24 with PDO SQLite and automated Chromium browser checks.

## Initial login

- Username: `admin`
- Password: `demoadmin`
- Created automatically on a fresh installation, without a forced password change.
- The optional demo seed uses the same credentials.
- Existing accounts and changed passwords are preserved.

## Changes

- Translated application labels, placeholders, tooltips, empty states, confirmation dialogs, notifications, validation errors, API errors, password requirements, report headings, Excel sheets, and mobile notices into English.
- Set the document language and number formatting to English; transliterated the remaining built-in district names.
- Fixed startup code that deleted default vehicle categories and broke databases with vehicles referencing those categories. Saved fuel rates now remain unchanged between requests.
- Fixed stale route buttons after trip changes by refreshing route usage counts with each trip response.
- Corrected mobile report labels and report heading wrapping.
- Added real calendar-date validation, including rejection of dates such as February 30.
- Bundled the existing SheetJS Excel library and its license for exports without an external CDN connection.
- Updated installation and demo-data instructions.

## Passed checks

Nine API workflow groups:

1. Fresh database creation, default login, admin access, English locations, and default categories.
2. Vehicle creation/editing and duplicate plate, category, fuel, and odometer validation.
3. Route creation/editing, duplicate and location validation, and new cities/districts.
4. Trip creation/editing/deletion, fuel calculations, vehicle changes, odometer rollback, history restrictions, inactive vehicles, and invalid dates.
5. Report date/vehicle/fuel filters, pagination, full totals, and complete export data.
6. Saved fuel rates persist; old trip fuel values remain unchanged.
7. User creation, mandatory temporary-password replacement, admin endpoint restrictions, trip access approval/rejection/revocation, and password reset.
8. Five-user limit, user deletion, primary-admin protection, password changes, and logout/login.
9. English unknown-action errors and sign-in rate limiting.

Eight browser workflow groups:

1. Seeded admin login, invalid-login message, and session persistence on refresh.
2. Vehicle form validation, license plate formatting, creation, editing, and search.
3. Route location dropdowns, creation, editing, and address search.
4. Trip selectors, calculation preview, creation, and editing.
5. Report shortcuts, pagination, and an actual `.xlsx` download containing all 181 test records with English headings.
6. User management, first-login password form, role-based navigation, permission requests, reset, and deletion.
7. Settings validation, password visibility toggle, English mobile notices, correct mobile report labels, and a 390-pixel-wide report layout without horizontal page overflow.
8. Confirmation dialogs, trip deletion and odometer rollback, vehicle/route deletion, and logout.

Additional checks passed: PHP and JavaScript syntax, SQLite integrity and foreign keys, custom initial-admin environment variables, demo-seeder overwrite protection, and a first-party text audit. No JavaScript page errors occurred during the browser workflows.

## Package and limits

This is a ready-to-run PHP source build; no compilation is required. PHP 8+ with PDO SQLite is required. The ZIP contains no database, test accounts, test records, local runtime, or session files. Optional sample data can be generated with the documented seed command.

The existing mobile behavior remains: Search/reports and password settings are available; Trips, Vehicles, Routes, and Users show an English desktop-use notice. Tests cover local PHP/SQLite behavior and Chromium; they do not establish compatibility with every hosting provider or browser. User-entered text and existing database content are preserved as entered.
