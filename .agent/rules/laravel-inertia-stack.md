---
trigger: always_on
---

# Laravel + Inertia + React Architecture Standards
description: Strict guidelines for directory structure, component patterns, and data flow.

instructions:

## 1. Directory & File Conventions
- **Controllers:** Follow `[Feature]Controller.php`. Use `Inertia::render()` for all views.
- **Frontend:** Components must live in `resources/js/Pages/` (Views) or `resources/js/Components/` (Reusables).
- **Use dedicated Resources for passing props to Inertia. NEVER pass raw Eloquent models to the frontend.
- **Routes:** Group routes by feature in `routes/web.php`. Use named routes.

## 2. React & Inertia Standards
- **Component Style:** Functional components with TypeScript/JSX.
- **State Management:** Use Inertia `useForm` for all forms. Use `usePage().props` for global data (auth, flash).
- **Styling:** Tailwind CSS only. Avoid inline styles; use utility classes.
- **Links:** Always use the `<Link>` component from `@inertiajs/react` for internal navigation to maintain SPA state.

## 3. Backend Logic (The "Slim Controller" Rule)
- **Services:** All business logic must live in `app/Services/`. Controllers should only handle request validation and response orchestration.
- **Database:** Use Migrations and Seeders for all schema changes. Use Factory-driven testing.
- **Testing:** All features must have a corresponding Pest test in `tests/Feature`.

## 4. Spec-Driven Task Management
- Before starting a new feature, search `app/Services` and `resources/js/Components` to reuse existing logic.
- Update `TASKS.md` immediately after a successful `php artisan test` or browser verification.
- If a change requires a `composer install` or `npm install`, notify the user before executing.
