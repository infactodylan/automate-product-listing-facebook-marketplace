<?php

namespace App\Services\FacebookMarketplace;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class FacebookBulkUploadWriter
{
    public const SHEET_NAME = 'Bulk Upload Template';

    public const DATA_START_ROW = 5;

    /**
     * @param  array<int, array{title: string, price: int, condition: string, description: string, category: string}>  $rows
     */
    public function writeWorkbookToPath(array $rows, string $absolutePath): void
    {
        if (count($rows) > (int) config('facebook_marketplace.rows_per_workbook')) {
            throw new \InvalidArgumentException('A single workbook may include at most '.(int) config('facebook_marketplace.rows_per_workbook').' data rows.');
        }

        $template = (string) config('facebook_marketplace.bulk_upload_template_path');
        if (! is_file($template)) {
            throw new \RuntimeException('Facebook bulk upload template is missing at: '.$template);
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if ($sheet === null) {
            throw new \RuntimeException('Template is missing the "'.self::SHEET_NAME.'" sheet.');
        }

        $row = self::DATA_START_ROW;
        foreach ($rows as $data) {
            $sheet->setCellValue('A'.$row, $this->clip($data['title'], 150));
            $sheet->setCellValue('B'.$row, max(0, $data['price']));
            $sheet->setCellValue('C'.$row, $data['condition']);
            $sheet->setCellValue('D'.$row, $this->clip($data['description'], 5000));
            $sheet->setCellValue('E'.$row, $this->clip($data['category'], 500));
            $row++;
        }

        $writer = new XlsxWriter($spreadsheet);
        $writer->save($absolutePath);
    }

    private function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars);
    }
}
