# Deployment Guide (Laravel + React + Inertia + MySQL)

## 1. Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8+
- Web server (Apache/Nginx)

## 2. Environment
1. Copy `.env.example` to `.env`.
2. Set database:
   - `DB_CONNECTION=mysql`
   - `DB_HOST=localhost`
   - `DB_PORT=3306`
   - `DB_DATABASE=<database_name>`
   - `DB_USERNAME=root`
   - `DB_PASSWORD=<password>`
3. Set `APP_URL` to deployment URL.

## 3. Install and Build
1. `composer install --no-dev --optimize-autoloader`
2. `npm ci`
3. `npm run build`
4. `php artisan key:generate` (first deploy only)
5. `php artisan migrate --force`
6. Optional demo data: `php artisan db:seed --force`

## 4. Optimize
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`

## 5. Scheduler and Queue
- Configure cron to run every minute:
  - `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`
- This triggers:
  - due-service reminders (daily 10:00),
  - scheduled campaign dispatch (every 10 minutes).

## 6. Health Checks
- `php artisan test`
- Smoke test:
  - login,
  - create appointment,
  - complete appointment (verify loyalty ledger),
  - dispatch a campaign,
  - export reports CSV/PDF.

## 7. Backup and Rollback
- Backup DB before migrations.
- Rollback command (if required): `php artisan migrate:rollback --step=1`
- Keep previous build artifacts for quick web rollback.

