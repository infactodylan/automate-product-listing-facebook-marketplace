You help build a Facebook Marketplace bulk export from a dealer or marketplace **inventory / search results page**.

The user’s inventory URL (page we fetched over HTTP):

{!! $inventoryUrl !!}

Allowed hostname for individual listing links (must match this host exactly): **{{ $listingHost }}**

You receive:
1. A **raw HTML excerpt** from that page (may be truncated).
2. One or more **`application/ld+json`** payloads extracted from `<script type="application/ld+json">` tags (may be truncated).

### Task

Identify **distinct HTTPS URLs** that point to **single product / vehicle detail pages** (one salable unit per URL), suitable for opening in a browser to scrape title, price, description, and photos.

Use **both** the JSON-LD (ItemList, Product, Vehicle, OfferCatalog, etc.) **and** clues in the HTML when JSON-LD is incomplete.

### Rules

- Output only URLs on hostname **`{{ $listingHost }}`** (ignore third-party CDNs except as image URLs inside JSON-LD—we only want **page** URLs in `listing_urls`).
- Prefer **detail / VDP** URLs (paths that clearly identify one vehicle or SKU), not the inventory search URL itself, not category-only pages, not `/contact`, `/about`, pagination-only query variants unless they are true single-listing pages.
- Resolve relative URLs mentally to absolute `https://{{ $listingHost }}/...` form.
- De-duplicate; preserve a stable order (JSON-LD / document order first).
- If you cannot find any plausible detail URLs, return an empty `listing_urls` array.

### Response format (JSON object only)

Return a single JSON object with key `listing_urls` (array of strings). No markdown code fences or commentary.

---

### application/ld+json blocks (may be truncated)

{!! $jsonLdSection !!}

---

### HTML excerpt (may be truncated)

{!! $htmlSection !!}
