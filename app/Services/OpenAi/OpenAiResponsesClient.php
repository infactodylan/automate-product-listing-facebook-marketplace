<?php

namespace App\Services\OpenAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiResponsesClient
{
    /**
     * Run an agentic loop until there are no outstanding function calls or max rounds hit.
     *
     * @param  list<mixed>  $input  Initial Responses API input items (e.g. role messages).
     * @param  list<array<string, mixed>>  $tools
     * @param  array<string, mixed>|null  $reasoning  e.g. ["effort" => "medium"]
     * @return array<string, mixed> Final Responses API JSON payload (decoded).
     */
    public function runUntilIdle(
        string $model,
        array $input,
        array $tools,
        ?array $reasoning,
        callable $executeFunctionCall,
        int $maxRounds,
        ?array $extraPayload = null,
        ?OpenAiDebugArtifactSession $debugSession = null,
    ): array {
        $currentInput = $input;
        $round = 0;

        $debugSession?->writeResponsesInitialInput($currentInput);

        while ($round < $maxRounds) {
            $round++;
            $payload = array_merge([
                'model' => $model,
                'input' => $currentInput,
                'tools' => $tools,
                'tool_choice' => 'auto',
            ], $extraPayload ?? []);

            if ($reasoning !== null && $reasoning !== []) {
                $payload['reasoning'] = $reasoning;
            }

            $response = Http::withToken((string) config('openai.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('facebook_marketplace.scraper_timeout_seconds') + 120)
                ->post(config('openai.base_url').'/responses', $payload);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    'OpenAI Responses API error HTTP '.$response->status().': '.$response->body(),
                );
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();

            $debugSession?->writeResponsesRound($round, $data);

            $output = $data['output'] ?? [];
            if (! is_array($output)) {
                return $data;
            }

            $outputItemTypes = [];
            foreach ($output as $item) {
                if (is_array($item)) {
                    $outputItemTypes[] = (string) ($item['type'] ?? 'unknown');
                }
            }

            Log::debug('OpenAI Responses API request finished', [
                'round' => $round,
                'model' => $model,
                'response_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'output_item_types' => $outputItemTypes,
            ]);

            $currentInput = array_merge($currentInput, $output);

            $functionCalls = self::collectFunctionCalls($output);
            if ($functionCalls === []) {
                Log::debug('OpenAI Responses API idle (no pending function calls)', [
                    'round' => $round,
                    'response_id' => $data['id'] ?? null,
                ]);

                return $data;
            }

            Log::debug('OpenAI Responses API executing function call outputs', [
                'round' => $round,
                'call_count' => count($functionCalls),
                'names' => array_map(fn (array $c) => $c['name'], $functionCalls),
            ]);

            foreach ($functionCalls as $call) {
                $outputString = $executeFunctionCall(
                    (string) $call['name'],
                    (string) $call['arguments'],
                    (string) $call['call_id'],
                );

                $currentInput[] = [
                    'type' => 'function_call_output',
                    'call_id' => $call['call_id'],
                    'output' => $outputString,
                ];
            }
        }

        throw new \RuntimeException('OpenAI Responses tool loop exceeded max rounds ('.$maxRounds.').');
    }

    /**
     * @param  list<mixed>  $outputItems
     * @return list<array{name: string, arguments: string, call_id: string}>
     */
    public static function collectFunctionCalls(array $outputItems): array
    {
        $calls = [];
        foreach ($outputItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) !== 'function_call') {
                continue;
            }
            $name = $item['name'] ?? null;
            $arguments = $item['arguments'] ?? '';
            $callId = $item['call_id'] ?? null;
            if (! is_string($name) || ! is_string($arguments) || ! is_string($callId) || $callId === '') {
                continue;
            }
            $calls[] = [
                'name' => $name,
                'arguments' => $arguments,
                'call_id' => $callId,
            ];
        }

        return $calls;
    }
}
