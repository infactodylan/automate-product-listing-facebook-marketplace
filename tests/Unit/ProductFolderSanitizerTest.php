<?php

namespace Tests\Unit;

use App\Services\ProductFolderSanitizer;
use PHPUnit\Framework\TestCase;

class ProductFolderSanitizerTest extends TestCase
{
    public function test_it_sanitizes_invalid_characters_and_collapses_whitespace(): void
    {
        $sanitizer = new ProductFolderSanitizer;

        $folders = $sanitizer->folderNamesForTitles([
            '  Test / Title \\ Item  ',
        ]);

        $this->assertSame(['Test - Title - Item'], $folders);
    }

    public function test_it_disambiguates_duplicate_titles(): void
    {
        $sanitizer = new ProductFolderSanitizer;

        $folders = $sanitizer->folderNamesForTitles([
            'Same Title',
            'Same Title',
            'Same Title',
        ]);

        $this->assertSame([
            'Same Title',
            'Same Title-2',
            'Same Title-3',
        ], $folders);
    }
}
