<?php

namespace App\Services;

/**
 * Converts listing titles into stable cross-platform zip folder names and
 * resolves collisions deterministically (…-2, …-3).
 */
class ProductFolderSanitizer
{
    private const MAX_BASE_LENGTH = 120;

    /**
     * @param  array<int, string>  $titles  Display titles (same as spreadsheet TITLE column).
     * @return array<int, string> Parallel array of folder names by row index.
     */
    public function folderNamesForTitles(array $titles): array
    {
        $seen = [];
        $out = [];

        foreach ($titles as $title) {
            $base = $this->sanitizeSingleTitle($title);
            $key = mb_strtolower($base);

            if (! isset($seen[$key])) {
                $seen[$key] = 1;
                $folder = $base;
            } else {
                $seen[$key]++;
                $folder = $base.'-'.$seen[$key];
            }

            $out[] = $folder;
        }

        return $out;
    }

    private function sanitizeSingleTitle(string $title): string
    {
        $replacements = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
        $sanitized = str_replace($replacements, '-', $title);
        $sanitized = preg_replace('/\s+/u', ' ', $sanitized) ?? '';
        $sanitized = trim($sanitized);

        if ($sanitized === '') {
            return 'listing';
        }

        if (mb_strlen($sanitized) > self::MAX_BASE_LENGTH) {
            $hashFragment = substr(sha1($title), 0, 4);

            return mb_substr($sanitized, 0, self::MAX_BASE_LENGTH - 7).'…-'.$hashFragment;
        }

        return $sanitized;
    }
}
