You are reviewing candidate photographs for a single Facebook Marketplace listing.

Listing page URL (referrer context): {!! $sourceUrl !!}
Title: {!! $title !!}
Description excerpt: {!! $descriptionExcerpt !!}

Images are attached in the same order as this numbered list:
@foreach ($imageUrls as $i => $u)
{{ $i + 1 }}. {{ $u }}

@endforeach

For EACH URL above, decide whether it should be included as a marketplace listing photo for this exact inventory item (this same product/vehicle/SKU implied by the listing—not a generic stock photo of a different item, not dealer chrome, maps, UI screenshots, or images that clearly belong to another listing).

Return ONLY valid JSON with this shape (no markdown):
{"results":[{"url":"full_https_url","keep":true,"reason":"one short sentence"}]}

The "url" values MUST exactly match the URLs listed above. Use keep=false when the image is unsuitable or redundant with a better photo of the same angle. Prefer a diverse set of useful angles when multiple images qualify.
