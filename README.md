# Fleet Manager — AI-Assisted QA Portfolio Project

A lightweight fleet management and trip tracking web application built with PHP, SQLite, and vanilla JavaScript.

> **AI-assisted development disclosure:** I used **Codex CLI inside JetBrains WebStorm** to help implement and refine the application. My role focused on requirements, workflow design, business rules, test scenarios, exploratory testing, regression checks, and validating the results. I do not present myself as having hand-written all the code.

## Features

- Vehicle inventory, status, fuel categories, and odometer tracking
- Route management with city and district selection
- Trip creation, editing, and deletion
- Automatic distance, odometer, and fuel calculations
- Historical search, date filters, pagination, and Excel export
- Admin/user roles, permission requests, and account management

## Run locally

**Requirements:** PHP 8+, PDO SQLite extension, and a writable `data/` directory.

1. Extract the ZIP and open a terminal in the application folder.
2. Run `php -S localhost:8000`.
3. Open `http://localhost:8000`.
4. Sign in with username **`admin`** and password **`demoadmin`**. The account and database are created automatically on the first visit. You can use the app immediately.

To start with fictional sample data, run `python3 scripts/seed_demo.py` before opening the app. It creates 20 vehicles, 40 routes, and 180 trips with the same initial admin login. Python 3 and PHP CLI with PDO SQLite are required. The command refuses to overwrite an existing database unless `--force` is passed; use that option only for disposable demo data while the app is stopped.

Existing accounts and passwords are preserved when installing over an existing database. To use the default login, start with the empty `data/` directory included in this ZIP. Use **Settings → Security** to change your password; replacement passwords require at least 8 characters, an uppercase Latin letter, a number, and a special character.

## QA case study

The most important domain rule is that each trip stores a snapshot of its distance and fuel consumption. Editing a trip must subtract the previous trip distance from the previous vehicle and add the new distance to the selected vehicle; deleting a trip must reverse its historical odometer contribution.

Suggested regression checks:

- Add a trip and verify fuel = distance × vehicle fuel rate / 100.
- Edit a trip's vehicle and route; verify both vehicle odometers and the new fuel calculation.
- Delete a historical trip; verify odometer rollback and refreshed search totals.
- Verify that a route's later edits do not retroactively alter historical trip snapshots.
- Confirm regular users cannot invoke admin-only endpoints, even if they modify client requests.
- Check pagination, filters, empty states, and export totals.

## Stack

PHP · SQLite · JavaScript · HTML · CSS · Codex CLI · WebStorm

## Safety

No database is included in this build. The default credentials are intended for local demos. For a public installation, override them before the first visit or change the password in Settings. The `data/` directory is protected on Apache with an `.htaccess` deny rule; on Nginx or other servers, configure equivalent restrictions. PHP's built-in server is for local development only. Demo data is entirely fictional.

## Project context

This is an independent portfolio adaptation of a Georgian-language fleet-management application. English-language copy and sample records are intended for portfolio presentation.

Optionally set `FLEET_INITIAL_ADMIN_USERNAME` and `FLEET_INITIAL_ADMIN_PASSWORD` in the PHP environment before the first visit or seed command to override the defaults. `AVTOPARKI_DATA_DIR` optionally selects a private database directory. Do not commit private credentials to the repository.
