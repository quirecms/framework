<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Admin\Content\SideBySide;
use Quire\Content\Revision\DiffLine;

final class SideBySideTest extends TestCase
{
    public function testAChangedWordIsMarkedInItsSentence(): void
    {
        self::assertSame([
            [['same', 'Hosts open against Northside on Friday.']],
            [['same', 'Hosts open against Northside '], ['added', 'United '], ['same', 'on Friday'], ['added', ', a rematch'], ['same', '.']],
        ], SideBySide::words('Hosts open against Northside on Friday.', 'Hosts open against Northside United on Friday, a rematch.'));
    }

    public function testRemovedAndAddedLinesArePairedAndTheRestFolded(): void
    {
        $lines = [
            new DiffLine(DiffLine::SAME, 'One.', 1, 1),
            new DiffLine(DiffLine::SAME, '', 2, 2),
            new DiffLine(DiffLine::SAME, 'Two.', 3, 3),
            new DiffLine(DiffLine::SAME, 'Three.', 4, 4),
            new DiffLine(DiffLine::REMOVED, 'Group D: TBC', 5, null),
            new DiffLine(DiffLine::ADDED, 'Group D: Eastfield', null, 5),
            new DiffLine(DiffLine::ADDED, 'Streamed live.', null, 6),
            new DiffLine(DiffLine::SAME, 'Four.', 6, 7),
        ];

        $rows = SideBySide::rows($lines);

        self::assertSame(['skip', 'same', 'change', 'change', 'same'], array_column($rows, 'kind'));
        self::assertSame(2, $rows[0]['count'], 'Blank lines between paragraphs aren\'t counted.');
        self::assertSame([['same', 'Group D: '], ['removed', 'TBC']], $rows[2]['old']);
        self::assertNull($rows[3]['old'], 'An added line has nothing beside it.');
        self::assertSame([['added', 'Streamed live.']], $rows[3]['new']);
    }

    public function testVeryLongLinesAreMarkedWhole(): void
    {
        $old = str_repeat('word ', 400);

        self::assertSame([[['removed', $old]], [['added', $old.'more']]], SideBySide::words($old, $old.'more'));
    }
}
