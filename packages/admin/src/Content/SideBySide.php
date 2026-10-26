<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Revision\DiffLine;

/**
 * A text's changes side by side, as a review shows them: what's live on the left, the changes on the
 * right. Each run of removed lines is paired with the run of added lines after it, and paired lines are
 * compared word by word, so a changed word is marked in its sentence. Unchanged lines near changes are
 * shown on both sides; the others are counted.
 *
 * @internal
 *
 * @phpstan-type Segment array{0: 'same'|'removed'|'added', 1: string}
 * @phpstan-type Row array{kind: 'same'|'change'|'skip', old: ?list<Segment>, new: ?list<Segment>, count: int}
 */
final class SideBySide
{
    /** Unchanged lines shown around changes */
    private const int CONTEXT = 1;

    /** Words compared at most: longer lines are marked whole */
    private const int MAX_TOKENS = 600;

    /**
     * @param list<DiffLine> $lines
     *
     * @return list<Row>
     */
    public static function rows(array $lines): array
    {
        $rows = [];
        $count = \count($lines);

        for ($index = 0; $index < $count;) {
            $line = $lines[$index];

            if (DiffLine::SAME === $line->operation) {
                $rows[] = ['kind' => 'same', 'old' => [['same', $line->text]], 'new' => [['same', $line->text]], 'count' => 1];
                ++$index;

                continue;
            }

            $removed = [];
            $added = [];

            while ($index < $count && DiffLine::REMOVED === $lines[$index]->operation) {
                $removed[] = $lines[$index++]->text;
            }

            while ($index < $count && DiffLine::ADDED === $lines[$index]->operation) {
                $added[] = $lines[$index++]->text;
            }

            for ($pair = 0, $pairs = max(\count($removed), \count($added)); $pair < $pairs; ++$pair) {
                $old = $removed[$pair] ?? null;
                $new = $added[$pair] ?? null;
                [$oldSegments, $newSegments] = null !== $old && null !== $new ? self::words($old, $new) : [null === $old ? null : [['removed', $old]], null === $new ? null : [['added', $new]]];
                $rows[] = ['kind' => 'change', 'old' => $oldSegments, 'new' => $newSegments, 'count' => 1];
            }
        }

        return self::fold($rows);
    }

    /**
     * Compares two lines word by word.
     *
     * @return array{0: list<Segment>, 1: list<Segment>}
     */
    public static function words(string $old, string $new): array
    {
        $a = self::tokens($old);
        $b = self::tokens($new);

        if (\count($a) * \count($b) > self::MAX_TOKENS ** 2 / 4 || \count($a) > self::MAX_TOKENS || \count($b) > self::MAX_TOKENS) {
            return [[['removed', $old]], [['added', $new]]];
        }

        // Longest common subsequence of the tokens, from the end.
        $lengths = array_fill(0, \count($a) + 1, array_fill(0, \count($b) + 1, 0));

        for ($i = \count($a) - 1; $i >= 0; --$i) {
            for ($j = \count($b) - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $left = [];
        $right = [];

        for ($i = 0, $j = 0; $i < \count($a) || $j < \count($b);) {
            if ($i < \count($a) && $j < \count($b) && $a[$i] === $b[$j]) {
                self::push($left, 'same', $a[$i++]);
                self::push($right, 'same', $b[$j++]);
            } elseif ($j < \count($b) && ($i >= \count($a) || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                self::push($right, 'added', $b[$j++]);
            } else {
                self::push($left, 'removed', $a[$i++]);
            }
        }

        return [$left, $right];
    }

    /**
     * Words, and the spaces and punctuation between them, so a change marks whole words.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        preg_match_all('/\w+|\s+|[^\w\s]/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Appends a token, joining it to the last segment of the same kind.
     *
     * @param list<Segment>            $segments
     * @param 'same'|'removed'|'added' $kind
     */
    private static function push(array &$segments, string $kind, string $token): void
    {
        $last = array_key_last($segments);

        if (null !== $last && $segments[$last][0] === $kind) {
            $segments[$last][1] .= $token;
        } else {
            $segments[] = [$kind, $token];
        }
    }

    /**
     * Folds unchanged lines away from changes into counted rows, and leaves out empty unchanged ones
     * (the blank lines between paragraphs).
     *
     * @param list<Row> $rows
     *
     * @return list<Row>
     */
    private static function fold(array $rows): array
    {
        $near = [];

        foreach ($rows as $index => $row) {
            if ('change' === $row['kind']) {
                foreach (range(max(0, $index - self::CONTEXT), min(\count($rows) - 1, $index + self::CONTEXT)) as $shown) {
                    $near[$shown] = true;
                }
            }
        }

        $folded = [];
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $blank = 'same' === $row['kind'] && '' === trim($row['old'][0][1] ?? '');

            if ('same' === $row['kind'] && (!isset($near[$index]) || $blank)) {
                $skipped += $blank ? 0 : 1;

                continue;
            }

            if ($skipped > 0) {
                $folded[] = ['kind' => 'skip', 'old' => null, 'new' => null, 'count' => $skipped];
                $skipped = 0;
            }

            $folded[] = $row;
        }

        if ($skipped > 0 && [] !== $folded) {
            $folded[] = ['kind' => 'skip', 'old' => null, 'new' => null, 'count' => $skipped];
        }

        return $folded;
    }
}
