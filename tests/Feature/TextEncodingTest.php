<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class TextEncodingTest extends TestCase
{
    public function test_application_text_files_are_valid_utf8_without_mojibake(): void
    {
        $directories = [
            app_path(),
            config_path(),
            database_path(),
            public_path(),
            resource_path(),
            base_path('routes'),
        ];

        $textExtensions = ['php', 'js', 'css', 'json', 'md', 'html'];
        $mojibakeMarkers = [
            "\u{00C3}",
            "\u{00C2}",
            "\u{00E2}",
            "\u{00F0}",
            "\u{FFFD}",
        ];
        $problems = [];

        foreach ($directories as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $textExtensions, true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if ($contents === false || preg_match('//u', $contents) !== 1) {
                    $problems[] = $file->getPathname().': UTF-8 inválido';

                    continue;
                }

                foreach ($mojibakeMarkers as $marker) {
                    if (str_contains($contents, $marker)) {
                        $problems[] = $file->getPathname().': posible mojibake';
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
    }
}
