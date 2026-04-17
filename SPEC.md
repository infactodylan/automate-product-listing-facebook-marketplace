# Product specification — Marketplace listing spreadsheet export

This document guides implementation of the Laravel + Livewire + DaisyUI application in this repository.

## Problem

Sellers and businesses maintain listings on a website. Facebook Marketplace supports bulk workflows using a spreadsheet plus images. Manually copying each listing is slow.

## Goal

Users submit a URL to their **product listings page**. The application:

1. Visits that page (and follows pagination or listing index patterns as needed).
2. Extracts a normalized list of products (title, price, description, and image URLs at minimum).
3. Produces **one Excel workbook** that follows the same structure as **`storage/Facebook Bulk Upload Template.xlsx`** (see “Spreadsheet” below). The implementation must load that file (or a path from `FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH`) and only populate data cells—never hand-roll a new workbook layout for export.
4. Downloads remote product images and packages them into a **zip archive** structured so users can match images to spreadsheet rows quickly.
5. Delivers everything through a **single secret link** that expires after **7 days**.

Non-goals for v1 (unless explicitly added later): user accounts, editing listings in-app, automated posting to Facebook, guaranteeing extraction from every possible site layout without configuration.

---

## Primary user flows

### Submit a listings URL

1. User lands on the home screen and enters the listings page URL.
2. User starts a job (“Generate export”). The UI shows progress (queued → fetching → parsing → downloading images → building files → ready).
3. On success, the UI shows the **delivery link** (copy button) and the **expiry time** (now + 7 days).
4. On failure, show a clear error (blocked site, timeout, parse failure) and optional support hints.

### Open delivery link (within 7 days)

1. Anonymous visitor opens the unique URL (no login required unless you add accounts later).
2. Page explains what’s inside and offers a **Download zip** button.
3. Zip downloads include the spreadsheet at the root and per-product image folders as specified below.

### Expired link

1. After expiry, the same URL returns HTTP 410 Gone or a friendly “expired” page with no file access.

---

## Deliverable formats

### Zip layout (stable contract)

At the zip root:

- `listings.xlsx` (name is canonical for this project; alias `inventory.xlsx` only if duplicate detection requires it).

Then one folder **per product**, folder name **exactly matching the product title** used in the spreadsheet for that row (after sanitization for filesystem safety).

Example:

```text
listings.xlsx
Vintage Desk Lamp/
  01.jpg
  02.jpg
  ...
Blue Ceramic Vase/
  01.jpg
  ...
```

**Folder naming rules**

- Derive from the **same display title** as the spreadsheet row (so users can visually match folder to row).
- Sanitize for cross-platform zip compatibility: strip/replace `/ \ : * ? " < > |`, collapse whitespace, trim, enforce max length (for example 120 chars) with a deterministic suffix if truncated (`…-a3f9`).
- If two products sanitize to the same folder name, append a short stable disambiguator (`-2`, `-3`, or a hash fragment) while keeping spreadsheet `Title` distinct as well.

**Images**

- Prefer original order from the source listing when obvious; otherwise alphabetical by discovered URL.
- Filename pattern `01.jpg`, `02.webp`, etc., preserving extension when possible.
- Skip broken downloads with a warning row in an optional `manifest.json` at zip root (recommended) listing `product_title`, `image_url`, `status`, `error`.

### Spreadsheet (Facebook Bulk Upload Template)

The canonical reference file is **`storage/Facebook Bulk Upload Template.xlsx`**. Laravel config **`config/facebook_marketplace.php`** exposes `bulk_upload_template_path` (override with env **`FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH`** when Meta ships a newer file).

**Rules**

1. **Generate exports by cloning this workbook** (PhpSpreadsheet: load template → write listing rows → save). Preserves the hidden **`VALIDATION`** sheet, data validation dropdowns, and formatting expected by Marketplace.
2. **Sheet name** visible to the user: **`Bulk Upload Template`** — do not rename.
3. **Fixed header block (do not overwrite with listing data)**  
   - Row 1: title row (`Facebook Marketplace Bulk Upload Template`).  
   - Row 2: instruction row (50 listings max per upload).  
   - Row 3: per-column requirement hints (REQUIRED / OPTIONAL).  
   - Row 4: column headers (**A–E**):  
     **`TITLE`** | **`PRICE`** | **`CONDITION`** | **`DESCRIPTION`** | **`CATEGORY`**
4. **Data rows** start at **row 5**. Each inventory row maps one listing to columns **A–E** only. Do **not** add extra columns unless Meta’s template changes and this file is updated accordingly (extra columns can break bulk import).
5. **Limits (from template copy)**  
   - **TITLE**: plain text, **up to 150 characters**.  
   - **PRICE**: whole number **in USD ($)** as the template expects (example row uses numeric `20` for price).  
   - **CONDITION**: must be exactly one of: **`New`**, **`Used - Like New`**, **`Used - Good`**, **`Used - Fair`**. Map scraped condition strings to the nearest allowed value or default with a clear rule (document in code).  
   - **DESCRIPTION**: plain text, **up to 5000 characters**.  
   - **CATEGORY**: template row 3 marks this column **OPTIONAL**, but values must match the template’s taxonomy when provided (examples use **`Parent//Child//Leaf`** separated by **`//`**). Populate from scraped data only when mapped to an allowed **`VALIDATION`** dropdown value.
6. **Batch size**: template text states **up to 50 listings at once**. Cap each generated workbook at **50 data rows** (rows 5–54). If inventory exceeds 50, split into multiple workbooks placed in the **same zip**, named `listings-part-01.xlsx`, `listings-part-02.xlsx`, … (single-listing batches still use the canonical **`listings.xlsx`**).

**Zip filename**: The delivered file may remain **`listings.xlsx`** inside the zip or use the same base layout as the template; the **internal workbook structure** must still match this template.

**Regression check**: When Meta updates the official template, replace **`storage/Facebook Bulk Upload Template.xlsx`**, adjust mappings if columns change, and add a snapshot test that row 4 headers and sheet names still match expectations after loading with PhpSpreadsheet.

---

## Delivery link requirements

- **Unguessable token**: use cryptographically random tokens (32+ bytes), not sequential ids.
- **Expiry**: link valid for **7 days** from job completion (or first issuance—pick one rule and document it in code comments).
- **Transport**: HTTPS only.
- **Authorization**: possession of the link is the credential (like a private download link). Optionally add optional email verification later.
- **Revocation**: deleting stored artifacts should invalidate downloads even within 7 days.

Storage paths should not be enumerable (rate-limit download attempts).

---

## System architecture (recommended)

### Jobs and queues

Scraping, image downloads, and zip creation are **long-running**. Use queued jobs:

1. `FetchListingIndexJob` — resolves listing URLs from the submitted page (+ pagination rules).
2. `ScrapeListingJob` (may run per listing or batched) — extracts fields.
3. `BuildExportJob` — builds xlsx + downloads images + writes zip to storage.
4. Optionally split `DownloadImagesJob` per batch for parallelism with concurrency limits.

Use `failed_jobs` visibility and retries with backoff for flaky hosts.

### Storage

- **Default Storage disk** (`FILESYSTEM_DISK` / `config/filesystems.default`) for zips and workspace paths under `exports/...`.
- Serve downloads via **signed routes** (`URL::temporarySignedRoute`) or streamed controller that checks expiry and logs access.
- Scheduled task to **delete** exports and temp dirs after expiry plus a safety buffer (for example purge at day 8).

### Crawling / parsing

- Prefer an HTTP client with sensible timeouts, user-agent identification, and robots.txt awareness (legal review may be required for production).
- Many inventory sites are JavaScript-rendered: plan for **Playwright or Puppeteer** behind a queue if static HTML is insufficient. Expose this as a “render mode” flag internally.
- Support site-specific extractors via a strategy interface (`Extractor` per hostname pattern) with a generic fallback (OpenGraph, JSON-LD `Product`, common CSS selectors).

### Excel generation

- Use **`phpoffice/phpspreadsheet`** (or equivalent). **Load `bulk_upload_template_path` from config**, fill rows starting at **row 5** on **`Bulk Upload Template`**, then save. Do not create a blank workbook with ad hoc headers.

---

## Application UI (Livewire + DaisyUI)

- Home: URL input, validation, job trigger, progress, result link.
- Delivery page: explanation, expiry countdown, download button.
- Use DaisyUI components (`card`, `btn`, `alert`, `progress`) for consistency.

---

## Configuration

Environment variables (suggested):

- `EXPORT_LINK_TTL_DAYS=7`
- `FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH=` (optional absolute path to Meta’s XLSX if not using `storage/Facebook Bulk Upload Template.xlsx`)
- `HTTP_USER_AGENT=` (service identifier)
- `SCRAPER_TIMEOUT_SECONDS=`
- `MAX_LISTINGS_PER_JOB=` (cost control)
- `MAX_TOTAL_IMAGE_BYTES=` (combined downloaded image bytes budget per export)
- `EXPORT_CREATE_RATE_PER_MINUTE=` / `EXPORT_DOWNLOAD_RATE_PER_MINUTE=` (per-IP Laravel rate limits)
- `EXPORT_PURGE_BUFFER_HOURS=` (scheduled deletion buffer after advertised expiry)
---

## Security and abuse prevention

- Rate-limit job creation per IP / per fingerprint.
- Cap listings per export and total image bytes.
- Block private IP ranges and SSRF hazards for user-supplied URLs.
- Log domains processed for operational monitoring.

---

## Testing checklist

- Unit tests for title → folder sanitization and collision handling.
- Assert `config('facebook_marketplace.bulk_upload_template_path')` exists on disk in CI (the committed template file).
- Feature tests for delivery URL expiry (**410**) after `expires_at` plus end-to-end job fixtures (no live scraping in CI).
- Job tests with recorded HTTP fixtures (do not hit live sites in CI).

---

## Implementation mapping (this repository)

This section tracks how the specification above maps to shipped code paths (keep it accurate as behavior evolves).

### Delivery links

Delivery links expire **7 days after the export succeeds** (`ListingExport::$expires_at` set at the end of `BuildExportJob`). Unguessable tokens are **64 hex characters** (`random_bytes(32)`), persisted only as `hash('sha256', token)` (`ListingExport::$delivery_token_hash`). Downloads validate the raw token against that hash and enforce both `expires_at` and filesystem presence.

### Routing + UI

- **`/`**: Livewire `App\Livewire\HomePage` — submits a listings URL, runs chained jobs (sync during tests; queue workers in production), polls progress, then shows copy/open for `/d/{token}`.
- **`/d/{token}`**: Livewire `App\Livewire\ExportDelivery` — explains contents and links to download (HTTP **410** after expiry via `abort(410)`).
- **`/d/{token}/download`**: streams the zip after the same expiry + readiness checks (`ExportDownloadController`).

### Pipeline

Jobs are dispatched in order using `Bus::chain`:

1. **`FetchListingIndexJob`** discovers candidate listing URLs (`ListingIndexExtractor`) and saves them on the export row.
2. **`ScrapeListingJob`** scrapes JSON-LD `Product` / Open Graph fallbacks (`ListingPageScraper`) into `scraped_products`.
3. **`BuildExportJob`** loads Meta’s workbook template (`FacebookBulkUploadWriter`), downloads listing images under per-title folders (`MarketplaceExportPackageBuilder`), and writes `exports/{storage_key}/export.zip` on Laravel’s **default Storage disk** (`FILESYSTEM_DISK`).

Pagination / infinite-scroll indexes are **not fully implemented** yet; unsupported layouts may require later Playwright-backed rendering (`SCRAPER_RENDER_MODE`-style flag is still future work).

### Abuse controls + housekeeping

Exports are rate-limited via `EXPORT_CREATE_RATE_PER_MINUTE` / `EXPORT_DOWNLOAD_RATE_PER_MINUTE` middleware aliases (`create-export`, `download-export`). SSRF defenses live in `UrlSafetyValidator`.

Expired artifacts are deleted by `exports:purge-expired` (`EXPORT_PURGE_BUFFER_HOURS`, default **24**) scheduled daily from `routes/console.php` bootstrap wiring.

---

## Compliance note

Automated access to third-party sites may be restricted by terms of service and `robots.txt`. Operators should obtain legal review before production deployment.
