<?php

namespace Tests\Unit;

use App\Services\OpenAi\OpenAiDebugArtifactSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiDebugArtifactSessionTest extends TestCase
{
    public function test_it_returns_null_when_disabled(): void
    {
        config(['openai.debug_artifacts_enabled' => false]);

        $this->assertNull(OpenAiDebugArtifactSession::start('kind', 'https://example.com'));
    }

    public function test_it_writes_meta_readme_and_json_when_enabled(): void
    {
        Storage::fake('local');
        config(['openai.debug_artifacts_enabled' => true]);

        $session = OpenAiDebugArtifactSession::start('my-kind', 'https://dealership.example/inventory');

        $this->assertNotNull($session);

        Storage::disk('local')->assertExists($session->basePath().'/meta.json');
        Storage::disk('local')->assertExists($session->basePath().'/README.txt');

        $session->writeJson('extra.json', ['ok' => true]);

        Storage::disk('local')->assertExists($session->basePath().'/extra.json');
        $decoded = json_decode((string) Storage::disk('local')->get($session->basePath().'/extra.json'), true);
        $this->assertTrue($decoded['ok'] ?? false);
    }

    public function test_it_downloads_candidate_urls_when_saving_http_files(): void
    {
        Storage::fake('local');
        config([
            'openai.debug_artifacts_enabled' => true,
            'facebook_marketplace.http_user_agent' => 'TestUA/1.0',
        ]);

        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response("\xff\xd8\xff\xe0dummy", 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $session = OpenAiDebugArtifactSession::start('vision', 'x');
        $this->assertNotNull($session);

        $written = $session->saveHttpUrlsAsFiles(['https://cdn.example.com/a.jpg'], 'candidate');

        $this->assertCount(1, $written);
        Storage::disk('local')->assertExists($written[0]);
    }
}
