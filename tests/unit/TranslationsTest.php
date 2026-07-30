<?php

namespace lameco\dash\tests\unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every user-facing string must have a Dutch entry in src/translations/nl/dash-dam.php.
 * English needs no file: the source strings are the English translation.
 */
final class TranslationsTest extends TestCase
{
    public function testEverySourceStringHasADutchEntry(): void
    {
        $strings = self::sourceStrings();

        self::assertNotEmpty($strings);

        $missing = array_diff($strings, array_keys(self::dutchEntries()));

        self::assertSame([], array_values($missing), "Missing from nl/dash-dam.php:\n" . implode("\n", $missing));
    }

    public function testDutchFileCarriesNoStaleEntries(): void
    {
        $stale = array_diff(array_keys(self::dutchEntries()), self::sourceStrings());

        self::assertSame([], array_values($stale), "In nl/dash-dam.php but no longer in any source file:\n" . implode("\n", $stale));
    }

    public function testDutchEntriesAreNonEmptyStrings(): void
    {
        foreach (self::dutchEntries() as $source => $translation) {
            self::assertIsString($translation, (string)$source);
            self::assertNotSame('', trim($translation), (string)$source);
        }
    }

    /**
     * @return string[]
     */
    private static function sourceStrings(): array
    {
        $strings = [];

        foreach (self::sourceFiles() as $file) {
            $pattern = $file->getExtension() === 'twig'
                ? '~\'((?:[^\'\\\\]|\\\\.)*)\'\s*\|\s*t\(\s*\'dash-dam\'~'
                : '~Craft::t\(\s*\'dash-dam\',\s*\'((?:[^\'\\\\]|\\\\.)*)\'~';

            preg_match_all($pattern, (string)file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $raw) {
                $strings[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function sourceFiles(): iterable
    {
        $src = dirname(__DIR__, 2) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (str_contains($file->getPathname(), '/translations/')) {
                continue;
            }

            if (in_array($file->getExtension(), ['php', 'twig'], true)) {
                yield $file;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function dutchEntries(): array
    {
        $file = dirname(__DIR__, 2) . '/src/translations/nl/dash-dam.php';

        self::assertFileExists($file);

        $entries = require $file;

        self::assertIsArray($entries);

        return $entries;
    }
}
