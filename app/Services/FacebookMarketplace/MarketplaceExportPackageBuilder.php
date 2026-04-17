<?php

namespace App\Services\FacebookMarketplace;

use App\Models\ListingExport;
use App\Services\ProductFolderSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class MarketplaceExportPackageBuilder
{
    public function __construct(
        private ConditionMapper $conditions,
        private ProductFolderSanitizer $folders,
        private FacebookBulkUploadWriter $writer,
    ) {}

    /**
     * Builds a zip on the default Storage disk and returns its path relative to that disk root.
     *
     * @param  array<int, array<string, mixed>>  $scrapedProducts
     */
    public function build(ListingExport $export, array $scrapedProducts): string
    {
        if ($scrapedProducts === []) {
            throw new \RuntimeException('No listings were scraped.');
        }

        $baseDir = 'exports/'.$export->storage_key;
        $packageDir = $baseDir.'/package';

        Storage::deleteDirectory($packageDir);
        Storage::makeDirectory($packageDir);

        $normalizedRows = [];
        foreach ($scrapedProducts as $p) {
            $normalizedRows[] = [
                'title' => $this->clip((string) ($p['title'] ?? 'Listing'), 150),
                'price' => isset($p['price_usd']) ? max(0, (int) $p['price_usd']) : 0,
                'condition' => $this->conditions->toAllowedCondition(isset($p['condition_raw']) ? (string) $p['condition_raw'] : null),
                'description' => $this->clip(trim((string) ($p['description'] ?? '')), 5000),
                'category' => $this->clip(trim((string) ($p['category'] ?? '')), 500),
            ];
        }

        $titles = array_map(fn (array $r) => $r['title'], $normalizedRows);
        $folderNames = $this->folders->folderNamesForTitles($titles);

        $rowsPerBook = (int) config('facebook_marketplace.rows_per_workbook');
        $chunks = array_chunk($normalizedRows, $rowsPerBook);

        foreach ($chunks as $i => $chunk) {
            $name = count($chunks) === 1
                ? 'listings.xlsx'
                : sprintf('listings-part-%02d.xlsx', $i + 1);

            $relativePath = $packageDir.'/'.$name;
            $absolute = Storage::path($relativePath);
            $this->writer->writeWorkbookToPath($chunk, $absolute);
        }

        $maxImageBytes = (int) config('facebook_marketplace.max_total_image_bytes');
        $imageBytesUsed = 0;
        $manifestImages = [];

        foreach ($scrapedProducts as $idx => $product) {
            $folder = $folderNames[$idx] ?? ('listing-'.($idx + 1));
            $urls = isset($product['image_urls']) && is_array($product['image_urls']) ? $product['image_urls'] : [];
            $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));

            $n = 1;
            foreach ($urls as $imageUrl) {
                $ext = $this->guessExtensionFromUrl($imageUrl);
                $relativeFile = $packageDir.'/'.$folder.'/'.sprintf('%02d.%s', $n, $ext);

                try {
                    $resp = Http::withHeaders([
                        'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
                        'Accept' => 'image/*,*/*',
                    ])
                        ->timeout(min(60, (int) config('facebook_marketplace.scraper_timeout_seconds')))
                        ->withOptions(['allow_redirects' => true])
                        ->get($imageUrl);

                    if (! $resp->successful()) {
                        throw new \RuntimeException('HTTP '.$resp->status());
                    }

                    $body = $resp->body();
                    $len = strlen($body);
                    if ($len <= 0) {
                        throw new \RuntimeException('Empty image body.');
                    }

                    if ($imageBytesUsed + $len > $maxImageBytes) {
                        throw new \RuntimeException('Total image budget exceeded.');
                    }

                    Storage::makeDirectory($packageDir.'/'.$folder);
                    Storage::put($relativeFile, $body);
                    $imageBytesUsed += $len;
                    $n++;
                } catch (\Throwable $e) {
                    $manifestImages[] = [
                        'product_title' => (string) ($product['title'] ?? ''),
                        'image_url' => $imageUrl,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        if ($manifestImages !== []) {
            Storage::put($packageDir.'/manifest.json', json_encode([
                'image_downloads' => $manifestImages,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $zipRelative = $baseDir.'/export.zip';
        Storage::makeDirectory(dirname($zipRelative));
        $zipAbsolute = Storage::path($zipRelative);

        if (file_exists($zipAbsolute)) {
            @unlink($zipAbsolute);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create zip archive.');
        }

        $packageAbsolute = Storage::path($packageDir);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageAbsolute, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $full = $file->getPathname();
            $relative = ltrim(str_replace($packageAbsolute.DIRECTORY_SEPARATOR, '', $full), DIRECTORY_SEPARATOR);
            $zip->addFile($full, str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        }

        $zip->close();

        return $zipRelative;
    }

    private function guessExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        return in_array($ext, $allowed, true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'jpg';
    }

    private function clip(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
