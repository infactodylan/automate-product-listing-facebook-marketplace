# Marketplace listing spreadsheet export

**Turn your site’s listings page into a Facebook Marketplace–ready Excel file and image bundle.** This Laravel app helps sellers and small businesses that keep **products or other items** on their own website and want to **bulk upload to Facebook Marketplace** without retyping every title, price, and photo.

If you found this repo while searching for *Facebook Marketplace bulk upload spreadsheet*, *inventory export to Excel*, or *bulk listing automation*, you are in the right place: the export format is designed around Meta’s bulk upload workbook (see `storage/Facebook Bulk Upload Template.xlsx` and [`SPEC.md`](SPEC.md)).

Example: turn the listings on https://www.fortwaynetoyota.com/searchused.aspx into a spreadsheet that can be uploaded to facebook marketplace.

---

## The problem

Facebook Marketplace supports **bulk listings** using a spreadsheet plus images—but your items often live on **your website**, not in a spreadsheet. Copying dozens or hundreds of listings into Meta’s template by hand is slow and error-prone. This application is meant to **visit your listings URL**, normalize what it finds, and produce a **downloadable zip**: an `.xlsx` aligned with Meta’s template and **folders of images per listing** so you can attach the right photos when you upload.

---

## What this project does (goal)

At a high level:

1. Accept a URL to your **product / inventory listings page**.
2. Fetch and parse listings (with queued jobs for heavier work—see `spec.md`).
3. Fill a workbook that follows the **Facebook bulk upload template** shipped in this repo.
4. Package **images grouped by listing** (folder names tied to listing titles).
5. Deliver a **time-limited download link** (as specified in `spec.md`).

Implementation details, row limits, and zip layout are documented in [`spec.md`](spec.md).

---

## Tech stack

- **Laravel** (PHP) with **Livewire** and **DaisyUI** / Tailwind for the UI
- **Excel**: PhpSpreadsheet (when export logic is implemented), based on `storage/Facebook Bulk Upload Template.xlsx`
- **Optional object storage**: `league/flysystem-aws-s3-v3` for S3-compatible disks (exports, assets, or backups—wire in `config/filesystems.php` as needed)

---

## Local development

Requirements:

- PHP **8.3+** and [Composer](https://getcomposer.org/)
- Node.js **20.19+** or **22.12+** (required by Vite 8) and npm

```bash
cp .env.example .env
composer install
php artisan key:generate

# JavaScript toolchain (use a supported Node version; nvm/fnm recommended)
npm install
npm run build   # or `npm run dev` while developing

php artisan migrate
php artisan serve
```

Open the URL shown by `artisan serve` (typically `http://127.0.0.1:8000`).

---

## Hosting and production

This is a standard Laravel application. You can run it anywhere that supports PHP 8.3+, a web server, and persistent storage:

| Concern | Notes |
|--------|--------|
| **Web server** | Nginx or Apache pointing `public/` as the document root; or a managed Laravel host ([Laravel Forge](https://forge.laravel.com), [Laravel Cloud](https://cloud.laravel.com), [Vapor](https://vapor.laravel.com), PaaS with PHP buildpacks). |
| **Environment** | Copy `.env.example` to `.env` on the server; set `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, and `APP_URL`. |
| **Database** | Configure `DB_*` (SQLite is fine for small setups; MySQL/PostgreSQL for production). Run `php artisan migrate`. |
| **Queues** | Listing export is expected to use **queues** (`php artisan queue:work` under Supervisor or your host’s worker system). See [`spec.md`](spec.md). |
| **Scheduler** | If you add expiry/cleanup commands, register `* * * * * php artisan schedule:run` in cron. |
| **Storage** | Exports use Laravel’s default Storage disk (`FILESYSTEM_DISK` / `config/filesystems.php`). Use the **`s3`** disk plus `AWS_*` / `AWS_BUCKET` when you need shared or remote object storage. |
| **Assets** | Build on deploy: `npm ci && npm run build`; ensure `public/build` exists or your host runs Vite build. |

For HTTPS, logging, and scaling, follow [Laravel’s deployment documentation](https://laravel.com/docs/deployment).

---

## Need help setting this up?

If you want this application **deployed, customized, or integrated** with your inventory site and workflows, **[Infacto Digital](https://infacto.digital)** can help with implementation, hosting strategy, queues, storage, and ongoing support.

---

## Documentation

- **[`spec.md`](spec.md)** — Product specification: flows, zip layout, spreadsheet rules, security, and testing notes.

---

## License

The Laravel framework is open source under the MIT license. Add a `LICENSE` file at the repo root when you finalize terms for this project.
