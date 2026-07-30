<?php

namespace lameco\dash\tests\unit;

use lameco\dash\helpers\CanonicalFolder;
use PHPUnit\Framework\TestCase;

final class CanonicalFolderTest extends TestCase
{
    public function testNoFoldersFallsBackToUnfiled(): void
    {
        self::assertSame(CanonicalFolder::UNFILED, CanonicalFolder::pick([], static fn(): bool => true));
    }

    public function testInScopeFolderBeatsAlphabeticallyEarlierUnselectedOne(): void
    {
        $inScope = static fn(string $path): bool => str_starts_with($path, 'Beeldbank');

        self::assertSame(
            'Beeldbank/Zorg',
            CanonicalFolder::pick(['Archief/Oud', 'Beeldbank/Zorg'], $inScope),
        );
    }

    public function testAlphabeticalTiebreakAmongInScopeFolders(): void
    {
        self::assertSame(
            'A/x',
            CanonicalFolder::pick(['B/y', 'A/x', 'C/z'], static fn(): bool => true),
        );
    }

    public function testAllFoldersUnselectedStillPicksAlphabeticallyFirst(): void
    {
        self::assertSame(
            'A/x',
            CanonicalFolder::pick(['B/y', 'A/x'], static fn(): bool => false),
        );
    }

    public function testNumericFolderNamesCompareAsStringsNotNumbers(): void
    {
        self::assertSame(
            '10',
            CanonicalFolder::pick(['9', '10'], static fn(): bool => true),
        );
    }

    public function testStableUnderCandidateReordering(): void
    {
        $candidates = ['Corporate/Foto', 'Beeldbank/Zorg', 'Archief/2020'];
        $inScope = static fn(string $path): bool => str_starts_with($path, 'Beeldbank')
            || str_starts_with($path, 'Corporate');

        foreach (self::permutations($candidates) as $permutation) {
            self::assertSame(
                'Beeldbank/Zorg',
                CanonicalFolder::pick($permutation, $inScope),
                'order: ' . implode(', ', $permutation),
            );
        }
    }

    /**
     * @param string[] $items
     * @return iterable<string[]>
     */
    private static function permutations(array $items): iterable
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);

            foreach (self::permutations(array_values($rest)) as $permutation) {
                yield [$item, ...$permutation];
            }
        }
    }
}
