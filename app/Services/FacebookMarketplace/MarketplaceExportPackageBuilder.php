<?php

namespace App\Services\FacebookMarketplace;

use App\Models\ListingExport;
use App\Services\ProductFolderSanitizer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        Log::info('Listing export package: building', [
            'export_id' => $export->id,
            'listing_count' => count($scrapedProducts),
        ]);

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
                ? 'listings.csv'
                : sprintf('listings-part-%02d.csv', $i + 1);

            $relativePath = $packageDir.'/'.$name;
            $absolute = Storage::path($relativePath);
            $this->writer->writeCsvToPath($chunk, $absolute);
        }

        foreach ($scrapedProducts as $idx => $_product) {
            $folder = $folderNames[$idx] ?? ('listing-'.($idx + 1));
            Storage::makeDirectory($packageDir.'/'.$folder);
        }

        Log::info('Listing export package: product folders created', [
            'export_id' => $export->id,
            'folder_count' => count($scrapedProducts),
        ]);

        $maxImageBytes = (int) config('facebook_marketplace.max_total_image_bytes');
        $imageBytesUsed = 0;
        $manifestImages = [];
        $budgetExhausted = false;

        foreach ($scrapedProducts as $idx => $product) {
            $folder = $folderNames[$idx] ?? ('listing-'.($idx + 1));
            $folderRelative = $packageDir.'/'.$folder;

            $urls = isset($product['image_urls']) && is_array($product['image_urls']) ? $product['image_urls'] : [];
            $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));

            $maxPerListing = (int) config('facebook_marketplace.max_images_per_listing');
            if ($maxPerListing > 0 && count($urls) > $maxPerListing) {
                Log::info('Listing export package: capping images per listing', [
                    'export_id' => $export->id,
                    'product_title' => (string) ($product['title'] ?? ''),
                    'urls_before' => count($urls),
                    'max_images_per_listing' => $maxPerListing,
                ]);
                $urls = array_slice($urls, 0, $maxPerListing);
            }

            $listingPageReferer = (string) ($product['source_url'] ?? '');
            $savedCount = 0;

            $urlList = array_values($urls);
            if ($urlList !== []) {
                $timeoutSeconds = min(60, (int) config('facebook_marketplace.scraper_timeout_seconds'));
                $poolConcurrency = max(1, (int) config('facebook_marketplace.image_download_concurrency'));

                Log::info('Listing export package: downloading listing images (concurrent pool)', [
                    'export_id' => $export->id,
                    'product_title' => (string) ($product['title'] ?? ''),
                    'url_count' => count($urlList),
                    'pool_concurrency' => $poolConcurrency,
                ]);

                $responses = Http::pool(function (Pool $pool) use ($urlList, $listingPageReferer, $timeoutSeconds) {
                    foreach ($urlList as $i => $imageUrl) {
                        $headers = [
                            'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
                            'Accept' => 'image/*,*/*',
                        ];
                        if ($listingPageReferer !== '') {
                            $headers['Referer'] = $listingPageReferer;
                        }

                        $pool->as((string) $i)
                            ->withHeaders($headers)
                            ->timeout($timeoutSeconds)
                            ->withOptions(['allow_redirects' => true])
                            ->get($imageUrl);
                    }
                }, $poolConcurrency);

                $n = 1;
                foreach ($urlList as $i => $imageUrl) {
                    if ($budgetExhausted) {
                        Log::info('Listing export package: image skipped (budget)', [
                            'export_id' => $export->id,
                            'product_title' => (string) ($product['title'] ?? ''),
                            'image_url' => $imageUrl,
                        ]);
                        $manifestImages[] = [
                            'product_title' => (string) ($product['title'] ?? ''),
                            'image_url' => $imageUrl,
                            'status' => 'skipped',
                            'error' => 'Total image download budget reached; remaining images skipped.',
                        ];

                        continue;
                    }

                    $ext = $this->guessExtensionFromUrl($imageUrl);
                    $relativeFile = $folderRelative.'/'.sprintf('%02d.%s', $n, $ext);

                    try {
                        $resp = $responses[(string) $i] ?? null;
                        if ($resp instanceof \Throwable) {
                            throw $resp;
                        }
                        if (! $resp instanceof Response) {
                            throw new \RuntimeException('Missing pool response for image.');
                        }

                        if (! $resp->successful()) {
                            throw new \RuntimeException('HTTP '.$resp->status());
                        }

                        $body = $resp->body();
                        $len = strlen($body);
                        if ($len <= 0) {
                            throw new \RuntimeException('Empty image body.');
                        }

                        if ($imageBytesUsed + $len > $maxImageBytes) {
                            $budgetExhausted = true;
                            Log::warning('Listing export package: image budget reached', [
                                'export_id' => $export->id,
                                'bytes_used' => $imageBytesUsed,
                                'max_bytes' => $maxImageBytes,
                                'image_url' => $imageUrl,
                            ]);
                            $manifestImages[] = [
                                'product_title' => (string) ($product['title'] ?? ''),
                                'image_url' => $imageUrl,
                                'status' => 'skipped',
                                'error' => 'Would exceed total image budget for this export.',
                            ];

                            continue;
                        }

                        Storage::put($relativeFile, $body);
                        $imageBytesUsed += $len;
                        $savedCount++;
                        Log::info('Listing export package: image saved', [
                            'export_id' => $export->id,
                            'product_title' => (string) ($product['title'] ?? ''),
                            'relative_file' => $relativeFile,
                            'bytes' => $len,
                        ]);
                        $n++;
                    } catch (\Throwable $e) {
                        Log::warning('Listing export package: image download failed', [
                            'export_id' => $export->id,
                            'product_title' => (string) ($product['title'] ?? ''),
                            'image_url' => $imageUrl,
                            'error' => $e->getMessage(),
                        ]);
                        $manifestImages[] = [
                            'product_title' => (string) ($product['title'] ?? ''),
                            'image_url' => $imageUrl,
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            if ($savedCount === 0 && $urls !== []) {
                Storage::put(
                    $folderRelative.'/image-download-errors.txt',
                    'No images could be saved for this listing. Check manifest.json at the root of this zip for per-URL errors.',
                );
            }

            if ($urls === []) {
                Storage::put(
                    $folderRelative.'/no-images-listed.txt',
                    'No image URLs were found while scraping this listing.',
                );
            }
        }

        Storage::put($packageDir.'/manifest.json', json_encode([
            'image_downloads' => $manifestImages,
            'image_bytes_used' => $imageBytesUsed,
            'image_bytes_budget' => $maxImageBytes,
            'budget_exhausted' => $budgetExhausted,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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

        Log::info('Listing export package: zip ready', [
            'export_id' => $export->id,
            'zip_relative_path' => $zipRelative,
            'image_bytes_used' => $imageBytesUsed,
        ]);

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
