<?php

namespace lameco\dash\tests\unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * English ships no translation file — the source strings are the English translation —
 * so only the nl file needs coverage.
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

    public function testDutchFileIsAlphabetical(): void
    {
        $keys = array_keys(self::dutchEntries());
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $keys);
    }

    /**
     * @return string[]
     */
    private static function sourceStrings(): array
    {
        $strings = [];

        foreach (self::sourceFiles() as $file) {
            $contents = (string)file_get_contents($file->getPathname());

            foreach (self::patterns($file->getExtension()) as $quote => $pattern) {
                preg_match_all($pattern, $contents, $matches);

                foreach ($matches[1] as $raw) {
                    $strings[] = str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $raw);
                }
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Patterns keyed by the quote style they capture. Both styles are matched so a
     * double-quoted source string cannot silently escape the coverage check; strings
     * built by concatenation or heredoc still can, so user-facing strings must stay
     * plain quoted literals.
     *
     * @return array<string, string>
     */
    private static function patterns(string $extension): array
    {
        $single = '\'((?:[^\'\\\\]|\\\\.)*)\'';
        $double = '"((?:[^"\\\\]|\\\\.)*)"';
        $category = '[\'"]dash-dam[\'"]';

        if ($extension === 'twig') {
            return [
                "'" => '~' . $single . '\s*\|\s*t\(\s*' . $category . '~',
                '"' => '~' . $double . '\s*\|\s*t\(\s*' . $category . '~',
            ];
        }

        return [
            "'" => '~Craft::t\(\s*' . $category . ',\s*' . $single . '~',
            '"' => '~Craft::t\(\s*' . $category . ',\s*' . $double . '~',
        ];
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function sourceFiles(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::srcDir(), FilesystemIterator::SKIP_DOTS));

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
        $file = self::srcDir() . '/translations/nl/dash-dam.php';

        self::assertFileExists($file);

        $entries = require $file;

        self::assertIsArray($entries);

        return $entries;
    }

    private static function srcDir(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }
}
