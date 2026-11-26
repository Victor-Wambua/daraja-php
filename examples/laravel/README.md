# Laravel snippets

Copy-in files that wire daraja-php into a Laravel 11/12 app: a service provider, config, controllers,
a migration and a scheduled reconciliation job.

> **Not run in CI, and not executed at all.** There is no Laravel skeleton in this repository. These files
> are written against Laravel's documented conventions and were only syntax-checked with `php -l`.
> Treat them as a starting point you read and adapt, not as tested code. The tested reference is
> [`../plain-php`](../plain-php), which exercises the same library calls end to end.

## Install

```bash
composer require victor-wambua/daraja-php
```

Copy the files into the matching folders of your app, then:

1. Add the keys from `config/daraja.php` to `.env` (see the repo's `.env.example`).
2. Register the provider in `bootstrap/providers.php`: `App\Providers\DarajaServiceProvider::class`.
3. Append `routes/snippet.php` to `routes/api.php`. API routes are stateless and skip CSRF, which a
   server-to-server callback needs. If you put the route in `web.php`, exclude it from CSRF verification.
4. `php artisan migrate`
5. Point `DARAJA_BASE_URL` at the mock (`bin/daraja-mock`) while developing.

## What is where

| File | Purpose |
| --- | --- |
| `config/daraja.php` | Reads the `DARAJA_*` environment variables |
| `app/Providers/DarajaServiceProvider.php` | Builds one `Daraja` client with Laravel's cache and logger, and a `CallbackProcessor` backed by your database |
| `app/Http/Controllers/CheckoutController.php` | Creates a pending payment, sends the STK prompt |
| `app/Http/Controllers/DarajaCallbackController.php` | Verifies, de-duplicates and applies the callback |
| `app/Jobs/ReconcilePendingPayments.php` | Asks Daraja about prompts that never got a callback |
| `database/migrations/…` | `payments` (with a UNIQUE receipt column) and `daraja_idempotency` |
| `routes/snippet.php` | The two routes |

The idempotency table is the library's `PdoIdempotencyStore` schema. The unique `mpesa_receipt` column on
`payments` is the second lock: even if the store were bypassed, the same receipt cannot be credited twice.
