<?php

namespace App\Services\OpenAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Persists local debug artifacts when {@see config('openai.debug_artifacts_enabled')} is true.
 *
 * OpenAI does not supply pixel screenshots for web_search; Responses rounds are saved as JSON.
 * Vision flows pass remote image URLs — we optionally download those bytes so you can open them locally.
 */
final class OpenAiDebugArtifactSession
{
    private function __construct(
        private string $baseRelativePath,
    ) {}

    public static function start(string $kind, string $uniqueKey): ?self
    {
        if (! config('openai.debug_artifacts_enabled')) {
            return null;
        }

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $kind));
        $slug = trim($slug, '-') ?: 'session';
        if (strlen($slug) > 48) {
            $slug = substr($slug, 0, 48);
        }

        $hash = substr(hash('sha256', $uniqueKey), 0, 10);
        $date = now()->format('Y-m-d');
        $base = "openai-debug/{$date}/{$slug}-{$hash}";

        Storage::disk('local')->makeDirectory($base);

        $meta = [
            'kind' => $kind,
            'created_at' => now()->toIso8601String(),
            'unique_key_hint' => substr($uniqueKey, 0, 240),
        ];
        Storage::disk('local')->put(
            "{$base}/meta.json",
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        );

        $readme = <<<'TXT'
OpenAI debug artifacts (enable with OPENAI_DEBUG_ARTIFACTS=true in .env).

• Listing index / listing images (Responses API): built-in web_search does not return rendered page screenshots.
  Files responses-input-initial.json and responses-round-*.json are the raw payloads exchanged with the API.
• Vision gate: candidate-*.jpg/png/webp are HTTP downloads of the same URLs passed as image_url to chat/completions
  (what the multimodal request references).

TXT;
        Storage::disk('local')->put("{$base}/README.txt", $readme);

        return new self($base);
    }

    public function basePath(): string
    {
        return $this->baseRelativePath;
    }

    /**
     * @param  list<mixed>  $input
     */
    public function writeResponsesInitialInput(array $input): void
    {
        $path = "{$this->baseRelativePath}/responses-input-initial.json";
        $json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        Storage::disk('local')->put($path, $json !== false ? $json : '[]');
    }

    /**
     * @param  array<string, mixed>  $responseJson
     */
    public function writeResponsesRound(int $round, array $responseJson): void
    {
        $path = "{$this->baseRelativePath}/responses-round-{$round}.json";
        $json = json_encode($responseJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        Storage::disk('local')->put($path, $json !== false ? $json : '{}');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJson(string $filename, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        Storage::disk('local')->put("{$this->baseRelativePath}/".$filename, $json !== false ? $json : '{}');
    }

    public function writeText(string $filename, string $text): void
    {
        Storage::disk('local')->put("{$this->baseRelativePath}/".$filename, $text);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string> Storage paths written
     */
    public function saveHttpUrlsAsFiles(array $urls, string $filenamePrefix, int $maxBytes = 6_000_000): array
    {
        $written = [];

        foreach ($urls as $i => $url) {
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            try {
                $resp = Http::timeout(60)
                    ->withHeaders([
                        'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
                        'Accept' => 'image/*,*/*;q=0.8',
                    ])
                    ->get($url);

                if (! $resp->successful()) {
                    $this->writeText("{$filenamePrefix}-{$n}-fetch-error.txt", $url."\nHTTP ".$resp->status());

                    continue;
                }

                $body = $resp->body();
                if (strlen($body) > $maxBytes) {
                    $this->writeText("{$filenamePrefix}-{$n}-too-large.txt", $url."\n".strlen($body).' bytes');

                    continue;
                }

                $ext = $this->guessExtension($url, $resp->header('Content-Type'));
                $rel = "{$this->baseRelativePath}/{$filenamePrefix}-{$n}.{$ext}";
                Storage::disk('local')->put($rel, $body);
                $written[] = $rel;
            } catch (\Throwable $e) {
                $this->writeText("{$filenamePrefix}-{$n}-fetch-error.txt", $url."\n".$e->getMessage());
            }
        }

        return $written;
    }

    private function guessExtension(string $url, ?string $contentType): string
    {
        $ct = strtolower((string) $contentType);
        if (str_contains($ct, 'jpeg') || str_contains($ct, 'jpg')) {
            return 'jpg';
        }
        if (str_contains($ct, 'png')) {
            return 'png';
        }
        if (str_contains($ct, 'webp')) {
            return 'webp';
        }
        if (str_contains($ct, 'gif')) {
            return 'gif';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('#\.(jpe?g|png|gif|webp)$#i', $path, $m)) {
            return strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        }

        return 'bin';
    }
}
