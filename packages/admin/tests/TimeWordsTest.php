<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Admin\Intent\TimeWords;

final class TimeWordsTest extends TestCase
{
    public function testTimesAsPeopleTypeThem(): void
    {
        $zone = new \DateTimeZone('Europe/Skopje');
        // A Monday afternoon.
        $now = new \DateTimeImmutable('2026-10-26 15:00', $zone);
        $at = static fn (string $text): array => [TimeWords::extract($text, $zone, $now)[0]?->format('Y-m-d H:i'), TimeWords::extract($text, $zone, $now)[1]];

        self::assertSame(['2026-10-30 18:00', 'spring draw'], $at('spring draw friday 18:00'));
        self::assertSame(['2026-10-27 09:00', 'venue guide'], $at('venue guide tomorrow at 9'));
        self::assertSame(['2026-10-26 17:30', 'results'], $at('results today 17:30'));
        self::assertSame(['2026-10-26 17:00', 'results'], $at('results in 2 hours'));
        self::assertSame(['2026-12-24 09:00', 'christmas'], $at('christmas on 2026-12-24 09:00'));
        self::assertSame(['2026-10-30 18:00', 'draw'], $at('draw 30 oct 18:00'));
        self::assertSame(['2026-11-02 08:00', 'draw'], $at('draw next monday at 8am'), 'Today is Monday: the next one.');
        self::assertSame(['2026-10-27 10:00', 'draw'], $at('draw at 10'), 'Past today: tomorrow.');
        self::assertSame(['2026-10-30 18:00', 'auslosung'], $at('auslosung freitag 18:00 uhr'));
        self::assertSame([null, 'spring draw'], $at('spring draw'));
    }
}
