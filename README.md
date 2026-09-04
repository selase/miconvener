# MiConvener

MiConvener is a standalone, API-first event management platform — events, programmes, speakers, registration, payments, settlement, attendance, engagement, and reporting, exposed entirely through a versioned public API, webhooks, and embeddable widgets. See [`miconvener.md`](miconvener.md) for the full product brief.

Built on a private multi-tenant Laravel SaaS starterkit (Laravel 13, PHP 8.5, Inertia.js + React tenant console). See [`manual.md`](manual.md) for the starterkit's architecture and conventions, and [`STARTERKIT.md`](STARTERKIT.md) for local setup.

## Quick start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
npm run dev
```

See [`manual.md`](manual.md) §1 for prerequisites and a walkthrough of each step.
