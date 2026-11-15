# CarRent

A car-rental web application built with **Symfony 7.4**, Doctrine ORM, Twig and Bootstrap (SB Admin 2 theme).
Visitors can browse the fleet, register, book a car and complete a (simulated) checkout; administrators manage cars, clients and reservations from a dashboard.

> **Demo project.** The payment step is a simulation: no payment provider is connected and no card data is stored or sent anywhere. Never enter a real card number.

## Features

- Public landing page and car catalogue (available cars only)
- Registration, login, logout (CSRF-protected), login throttling
- User profile with reservation history and profile editing
- Reservation creation / editing / deletion with automatic price calculation and car-availability tracking
- Simulated checkout and HTML confirmation e-mail
- Admin area: dashboard with fleet statistics (Chart.js), CRUD for cars (with image upload), clients and reservations
- Role-based access control (`ROLE_USER`, `ROLE_ADMIN`)

## Tech stack

PHP ≥ 8.2 · Symfony 7.4 · Doctrine ORM / Migrations · Twig · Symfony Security, Form, Validator, Mailer · SQLite (default) or MySQL · Chart.js

## Installation

```bash
git clone <your-repository-url> carrent && cd carrent
composer install

# Local configuration (never commit .env.local)
cp .env.example .env.local
# Generate a secret and paste it as APP_SECRET in .env.local:
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'

# Database (SQLite by default; migrations also seed a set of synthetic demo cars)
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction

# Create an administrator (you will be prompted for a password, min. 8 characters)
php bin/console app:create-admin <username> <email>

# Run
symfony server:start        # or: php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000>. For e-mails in development, run a local catcher such as [Mailpit](https://mailpit.axllent.org/) on port 1025 (default `MAILER_DSN`), or change `MAILER_DSN` in `.env.local`.

## Configuration

| Variable | Purpose |
|---|---|
| `APP_ENV` | `dev` or `prod` (use `prod` and `APP_DEBUG=0` when deployed) |
| `APP_SECRET` | Required. Long random string; keep it private |
| `DATABASE_URL` | SQLite (default) or MySQL DSN |
| `MAILER_DSN` | SMTP DSN used for confirmation e-mails |

Secrets belong in `.env.local` / real environment variables / [Symfony secrets](https://symfony.com/doc/current/configuration/secrets.html), never in committed files.

## Production deployment

`.env.example` is a **local development** template (see Installation above) and is deliberately left at `APP_ENV=dev` so `cp .env.example .env.local` keeps working for development. Use `.env.prod.example` as the reference for a real deployment instead — copy it to `.env.prod.local` (already covered by the `/.env.*.local` rule in `.gitignore`) or, preferably, set the same variables as real environment variables on your host. Never commit either file with real values.

1. **`APP_ENV=prod`** and **`APP_DEBUG=0`**. `config/packages/web_profiler.yaml` and `config/packages/debug.yaml` only enable the profiler and debug toolbar `when@dev`, so with `APP_ENV=prod` they stay off and stack traces are not shown to visitors — but only if `APP_ENV` is actually `prod`. Symfony's Dotenv component defaults `APP_DEBUG` to `0` automatically whenever `APP_ENV=prod` (see `vendor/symfony/dotenv/Dotenv.php`, `bootEnv()`), so setting it explicitly is defense in depth, not strictly required.
2. Install dependencies without the dev tooling: `composer install --no-dev --optimize-autoloader`.
3. Set `APP_SECRET`, `DATABASE_URL` and `MAILER_DSN` to their real production values, by the method described above.
4. Warm the cache for the production environment: `php bin/console cache:clear --env=prod --no-debug`.
5. Run migrations against the production database: `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`.
6. Compile the mapped assets for serving: `php bin/console asset-map:compile --env=prod`.

## Security notes

- Passwords are hashed with Symfony's `auto` hasher; forms and destructive actions use CSRF tokens.
- Admin routes are protected by `access_control` and `#[IsGranted]`; reservations are restricted to their owner or an admin.
- Image uploads are validated by real MIME type and size, and stored under random file names.
- Third-party scripts are loaded from public CDNs without Subresource Integrity; consider self-hosting or adding SRI for production.

## Project layout

```
src/Controller   HTTP controllers (Home, Security, Reservation, Payment, Voiture, User, Profile, Dashboard)
src/Entity       Doctrine entities: User, Voiture, Reservation
src/Form         Symfony form types
templates/       Twig templates
migrations/      Doctrine migrations (schema + synthetic demo cars)
```

## License

Proprietary – add a license of your choice before accepting contributions.
