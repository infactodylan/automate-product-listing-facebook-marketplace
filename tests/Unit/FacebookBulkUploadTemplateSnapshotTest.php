<?php

namespace Tests\Unit;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FacebookBulkUploadTemplateSnapshotTest extends TestCase
{
    public function test_committed_template_loads_and_matches_expected_layout_contract(): void
    {
        $path = config('facebook_marketplace.bulk_upload_template_path');

        $this->assertIsString($path);
        $this->assertFileExists($path);

        $spreadsheet = IOFactory::load($path);

        $this->assertContains('Bulk Upload Template', $spreadsheet->getSheetNames());
        $this->assertContains('VALIDATION', $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('Bulk Upload Template');
        $this->assertNotNull($sheet);

        $this->assertSame('TITLE', (string) $sheet->getCell('A4')->getValue());
        $this->assertSame('PRICE', (string) $sheet->getCell('B4')->getValue());
        $this->assertSame('CONDITION', (string) $sheet->getCell('C4')->getValue());
        $this->assertSame('DESCRIPTION', (string) $sheet->getCell('D4')->getValue());
        $this->assertSame('CATEGORY', (string) $sheet->getCell('E4')->getValue());
    }
}
