<?php

namespace Tests\Unit;

use App\Services\FacebookMarketplace\FacebookBulkUploadWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FacebookBulkUploadWriterXlsxTest extends TestCase
{
    public function test_xlsx_contains_same_grid_as_csv_for_one_row(): void
    {
        $dir = sys_get_temp_dir().'/fb_bulk_test_'.bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $csvPath = $dir.'/out.csv';
        $xlsxPath = $dir.'/out.xlsx';

        try {
            $rows = [[
                'title' => '2019 Honda Civic LX',
                'price' => 12995,
                'condition' => 'Used - Good',
                'description' => 'Low miles',
                'category' => '',
            ]];

            $writer = app(FacebookBulkUploadWriter::class);
            $writer->writeCsvToPath($rows, $csvPath);
            $writer->writeXlsxToPath($rows, $xlsxPath);

            $handle = fopen($csvPath, 'rb');
            $this->assertNotFalse($handle);
            $csvHeaders = fgetcsv($handle);
            $csvRow = fgetcsv($handle);
            fclose($handle);

            $spreadsheet = IOFactory::load($xlsxPath);
            $sheet = $spreadsheet->getActiveSheet();

            foreach ($csvHeaders as $colIndex => $expectedHeader) {
                $letter = chr(ord('A') + $colIndex);
                $this->assertSame(
                    trim((string) $expectedHeader),
                    trim((string) $sheet->getCell($letter.'1')->getCalculatedValue()),
                    'Header column '.($colIndex + 1),
                );
            }

            foreach ($csvRow as $colIndex => $expectedCell) {
                $letter = chr(ord('A') + $colIndex);
                $actual = $sheet->getCell($letter.'2')->getCalculatedValue();
                $this->assertSame((string) $expectedCell, (string) $actual, 'Data column '.($colIndex + 1));
            }
        } finally {
            if (is_file($csvPath)) {
                unlink($csvPath);
            }
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
            @rmdir($dir);
        }
    }
}
