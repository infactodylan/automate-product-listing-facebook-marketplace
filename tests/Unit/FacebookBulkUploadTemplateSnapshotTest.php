<?php

namespace Tests\Unit;

use Tests\TestCase;

class FacebookBulkUploadTemplateSnapshotTest extends TestCase
{
    public function test_committed_template_csv_matches_expected_header_contract(): void
    {
        $path = config('facebook_marketplace.bulk_upload_template_path');

        $this->assertIsString($path);
        $this->assertFileExists($path);

        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle);

        try {
            $headers = fgetcsv($handle);
            $this->assertNotFalse($headers);
            /** @var list<string> $headers */
            $headers = array_values(array_map(static fn ($h) => trim((string) $h), $headers));

            $this->assertSame(
                ['TITLE', 'PRICE', 'CONDITION', 'DESCRIPTION', 'CATEGORY'],
                array_map(static fn ($h) => strtoupper($h), $headers),
            );
        } finally {
            fclose($handle);
        }
    }
}
