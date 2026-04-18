<?php

namespace App\Services\FacebookMarketplace;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FacebookBulkUploadWriter
{
    /**
     * @param  array<int, array{title: string, price: int, condition: string, description: string, category: string}>  $rows
     */
    public function writeCsvToPath(array $rows, string $absolutePath): void
    {
        if (count($rows) > (int) config('facebook_marketplace.rows_per_workbook')) {
            throw new \InvalidArgumentException('A single CSV file may include at most '.(int) config('facebook_marketplace.rows_per_workbook').' data rows.');
        }

        $template = (string) config('facebook_marketplace.bulk_upload_template_path');
        if (! is_file($template)) {
            throw new \RuntimeException('Facebook bulk upload template CSV is missing at: '.$template);
        }

        $headers = $this->readTemplateHeaderRow($template);

        $parent = dirname($absolutePath);
        if (! is_dir($parent)) {
            mkdir($parent, 0775, true);
        }

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write CSV at: '.$absolutePath);
        }

        try {
            fputcsv($handle, $headers);

            foreach ($rows as $data) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = $this->cellForHeader((string) $header, $data);
                }
                fputcsv($handle, $line);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, array{title: string, price: int, condition: string, description: string, category: string}>  $rows
     */
    public function writeXlsxToPath(array $rows, string $absolutePath): void
    {
        if (count($rows) > (int) config('facebook_marketplace.rows_per_workbook')) {
            throw new \InvalidArgumentException('A single workbook may include at most '.(int) config('facebook_marketplace.rows_per_workbook').' data rows.');
        }

        $template = (string) config('facebook_marketplace.bulk_upload_template_path');
        if (! is_file($template)) {
            throw new \RuntimeException('Facebook bulk upload template CSV is missing at: '.$template);
        }

        $headers = $this->readTemplateHeaderRow($template);

        $parent = dirname($absolutePath);
        if (! is_dir($parent)) {
            mkdir($parent, 0775, true);
        }

        $matrix = [$headers];
        foreach ($rows as $data) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $this->cellForHeader((string) $header, $data);
            }
            $matrix[] = $line;
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($matrix);

        try {
            (new Xlsx($spreadsheet))->save($absolutePath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return list<string>
     */
    private function readTemplateHeaderRow(string $templatePath): array
    {
        $handle = fopen($templatePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read template CSV at: '.$templatePath);
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === [null]) {
                throw new \RuntimeException('Template CSV has no header row: '.$templatePath);
            }

            /** @var list<string> $headers */
            $headers = array_values(array_map(static fn ($h) => trim((string) $h), $headers));

            $canonical = ['TITLE', 'PRICE', 'CONDITION', 'DESCRIPTION', 'CATEGORY'];
            $normalized = array_map(static fn (string $h) => strtoupper($h), $headers);
            foreach ($canonical as $i => $expect) {
                if (($normalized[$i] ?? null) !== $expect) {
                    throw new \RuntimeException(
                        'Template CSV header mismatch at column '.($i + 1).': expected '.$expect.', got '.($headers[$i] ?? 'missing').'.',
                    );
                }
            }

            return $headers;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{title: string, price: int, condition: string, description: string, category: string}  $data
     */
    private function cellForHeader(string $header, array $data): string
    {
        return match (strtoupper(trim($header))) {
            'TITLE' => $this->clip($data['title'], 150),
            'PRICE' => (string) max(0, $data['price']),
            'CONDITION' => $data['condition'],
            'DESCRIPTION' => $this->clip($data['description'], 5000),
            'CATEGORY' => $this->clip($data['category'], 500),
            default => '',
        };
    }

    private function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars);
    }
}
