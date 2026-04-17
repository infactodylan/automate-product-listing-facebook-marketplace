# Product specification — Car listing spreadsheet creator

This document guides implementation of the Laravel + Livewire + DaisyUI application in this repository.

## Problem

Dealers and sellers maintain inventory on a website. Facebook Marketplace supports bulk workflows using a spreadsheet plus images. Manually copying each listing is slow.

## Goal

Users submit a URL to their **product listings page**. The application:

1. Visits that page (and follows pagination or listing index patterns as needed).
2. Extracts a normalized list of products (title, price, description, and image URLs at minimum).
3. Produces **one Excel workbook** compatible with Facebook Marketplace bulk listing expectations (exact column headers must be verified against Meta’s current template during implementation).
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
2019 Honda Civic LX/
  01.jpg
  02.jpg
  ...
2020 Toyota Camry LE/
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

### Spreadsheet

- Minimum columns for Facebook Marketplace bulk workflows typically include identifiers for listing data and image references; **validate the official Meta template** (CSV/XLSX) at build time and map columns explicitly in code (do not guess column names long-term).
- Include a stable **internal row id** column (UUID or incremental id) if Facebook allows extra columns to be ignored, to correlate rows with folders during debugging.
- Store price as number + currency code columns if the template requires it.

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

- **Private disk** (`storage/app/private/exports/...`) for zips and temp image dirs.
- Serve downloads via **signed routes** (`URL::temporarySignedRoute`) or streamed controller that checks expiry and logs access.
- Scheduled task to **delete** exports and temp dirs after expiry plus a safety buffer (for example purge at day 8).

### Crawling / parsing

- Prefer an HTTP client with sensible timeouts, user-agent identification, and robots.txt awareness (legal review may be required for production).
- Many inventory sites are JavaScript-rendered: plan for **Playwright or Puppeteer** behind a queue if static HTML is insufficient. Expose this as a “render mode” flag internally.
- Support site-specific extractors via a strategy interface (`Extractor` per hostname pattern) with a generic fallback (OpenGraph, JSON-LD `Product`, common CSS selectors).

### Excel generation

- Use `phpoffice/phpspreadsheet` or equivalent; write `.xlsx` with one row per product.

---

## Application UI (Livewire + DaisyUI)

- Home: URL input, validation, job trigger, progress, result link.
- Delivery page: explanation, expiry countdown, download button.
- Use DaisyUI components (`card`, `btn`, `alert`, `progress`) for consistency.

---

## Configuration

Environment variables (suggested):

- `EXPORT_LINK_TTL_DAYS=7`
- `HTTP_USER_AGENT=` (service identifier)
- `SCRAPER_TIMEOUT_SECONDS=`
- `MAX_LISTINGS_PER_JOB=` (cost control)

---

## Security and abuse prevention

- Rate-limit job creation per IP / per fingerprint.
- Cap listings per export and total image bytes.
- Block private IP ranges and SSRF hazards for user-supplied URLs.
- Log domains processed for operational monitoring.

---

## Testing checklist

- Unit tests for title → folder sanitization and collision handling.
- Feature tests for signed URL expiry and 410 after `expires_at`.
- Job tests with recorded HTTP fixtures (do not hit live sites in CI).

---

## Compliance note

Automated access to third-party sites may be restricted by terms of service and `robots.txt`. Operators should obtain legal review before production deployment.
