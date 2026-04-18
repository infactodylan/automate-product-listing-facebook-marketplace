Find direct HTTPS URLs for photographs of this inventory listing (product or vehicle).

Listing page URL: {!! $listingPageUrl !!}

Return at most 5 distinct image URLs for this listing only. Prefer clear photos that show the item being sold; omit duplicates, placeholders, and images that are not clearly for this listing.

Before including each URL, double-check that it is a real photograph of this same inventory item that a seller could reasonably post on Facebook Marketplace—actual listing photos, not unrelated stock art, dealer logos, maps, UI screenshots, watermarks-only graphics, or images that clearly belong to a different listing. Skip anything ambiguous or unsuitable for a marketplace listing.

### When photos are not in plain HTML (client-rendered galleries)

Galleries are often filled from the same kind of data **SPA network calls** use: JSON embedded in the listing page response (`__NEXT_DATA__`, `application/json` scripts, hydration state), `og:image` / Twitter cards, JSON-LD, or arrays of CDN image URLs in inline config. Treat those as primary sources when `<img>` tags or obvious gallery markup are missing—still only URLs that clearly belong to **this** listing item.

Use web_search / open-page as needed. Respond with terse confirmation text; photo URLs must be full https links ending in .jpg .jpeg .png .webp.
