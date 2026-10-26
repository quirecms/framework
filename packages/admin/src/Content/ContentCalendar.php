<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Type\ContentTypeDefinition;

/**
 * A month of a content list: what was published and what's scheduled, by day, and changes scheduled to
 * be published, in the site's time zone. The list's filters apply.
 *
 * @internal
 *
 * @phpstan-type Entry array{at: \DateTimeImmutable, item: Content, kind: 'live'|'scheduled'|'changes'|'draft', pending: ?PendingRevision}
 * @phpstan-type Day array{date: \DateTimeImmutable, inMonth: bool, today: bool, entries: list<Entry>}
 */
final readonly class ContentCalendar
{
    /** The most entries a month shows */
    public const int LIMIT = 500;

    public function __construct(
        private ContentLists $lists,
        private PendingRevisionRepository $pending,
        private RelativeTime $time,
    ) {
    }

    /**
     * @param string|null $month E.g. "2026-10"; the current month by default
     *
     * @return array{month: \DateTimeImmutable, previous: string, next: string, weeks: list<list<Day>>}
     */
    public function month(ContentTypeDefinition $type, ListState $state, ?string $month): array
    {
        $timezone = $this->time->timezone();
        $start = new \DateTimeImmutable(($month ?? new \DateTimeImmutable('now', $timezone)->format('Y-m')).'-01 00:00', $timezone);
        $end = $start->modify('first day of next month');
        $entries = [];

        foreach ($this->lists->filtered($type, $state)->where('published_at', '>=', $start)->where('published_at', '<', $end)->orderBy('published_at')->limit(self::LIMIT)->get() as $item) {
            \assert($item->publishedAt instanceof \DateTimeImmutable);
            $kind = match ($item->status) {
                'published' => 'live',
                'scheduled' => 'scheduled',
                default => 'draft',
            };
            $entries[] = ['at' => $item->publishedAt->setTimezone($timezone), 'item' => $item, 'kind' => $kind, 'pending' => null];
        }

        $scheduled = $this->pending->scheduledBetween($start, $end, self::LIMIT);
        $ids = array_values(array_unique(array_map(static fn (PendingRevision $pending): string => $pending->contentId, $scheduled)));
        $items = [];

        // Only changes to items the list shows, e.g. of this type and the user's own when that's all they edit.
        foreach ([] === $ids ? [] : $this->lists->filtered($type, $state)->where('id', 'in', $ids)->get() as $item) {
            $items[$item->id] = $item;
        }

        foreach ($scheduled as $pending) {
            if (isset($items[$pending->contentId]) && $pending->publishAt instanceof \DateTimeImmutable) {
                $entries[] = ['at' => $pending->publishAt->setTimezone($timezone), 'item' => $items[$pending->contentId], 'kind' => 'changes', 'pending' => $pending];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $byDay = [];

        foreach ($entries as $entry) {
            $byDay[$entry['at']->format('Y-m-d')][] = $entry;
        }

        // Whole weeks, Monday first, around the month.
        $today = new \DateTimeImmutable('now', $timezone)->format('Y-m-d');
        $day = $start->modify('-'.((int) $start->format('N') - 1).' days');
        $weeks = [];

        while ($day < $end) {
            $week = [];

            for ($i = 0; $i < 7; ++$i) {
                $week[] = ['date' => $day, 'inMonth' => $day->format('Y-m') === $start->format('Y-m'), 'today' => $day->format('Y-m-d') === $today, 'entries' => $byDay[$day->format('Y-m-d')] ?? []];
                $day = $day->modify('+1 day');
            }

            $weeks[] = $week;
        }

        return ['month' => $start, 'previous' => $start->modify('-1 month')->format('Y-m'), 'next' => $end->format('Y-m'), 'weeks' => $weeks];
    }
}
