<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard;

/**
 * Something on the home page's "Needs you": what waits for the user, and the one thing to do about it.
 *
 * The text is plain, except that what it's about can be marked with double asterisks, e.g.
 * "Grace asks you to review her changes to **Spring draw**": it's shown in bold, and the rest is escaped.
 *
 * @api
 */
final readonly class InboxItem
{
    public const string TONE_NEUTRAL = 'neutral';
    public const string TONE_REVIEW = 'review';
    public const string TONE_SCHEDULED = 'scheduled';
    public const string TONE_WARNING = 'warning';
    public const string TONE_DANGER = 'danger';

    /**
     * @param string                                   $key      Unique among the items, e.g. "review:<pending ID>"
     * @param string                                   $text     Translated, with **…** around what it's about
     * @param string                                   $url      Where the action leads
     * @param string                                   $action   The action's label, translated, e.g. "Review changes"
     * @param \DateTimeImmutable                       $at       Since when it waits: older items come first within a priority
     * @param int                                      $priority Higher comes first, e.g. 30 for what blocks others, 20 for what failed
     * @param string|null                              $person   The name of who it's from, shown as their initials
     * @param string|null                              $stage    Where the item is on its way: draft, review, scheduled or live
     * @param list<array{label: string, tone: string}> $tags     Short labels, with a tone (the TONE_* constants)
     * @param string|null                              $detail   A short line under it, translated
     * @param bool                                     $primary  Whether the action is the page's main one (the first such is)
     */
    public function __construct(
        public string $key,
        public string $text,
        public string $url,
        public string $action,
        public \DateTimeImmutable $at,
        public int $priority = 10,
        public ?string $person = null,
        public ?string $stage = null,
        public array $tags = [],
        public ?string $detail = null,
        public bool $primary = false,
    ) {
    }

    /**
     * Marks what the text is about, e.g. a title, to put it into the text: `['title' => InboxItem::about($item->title)]`.
     * Asterisks of its own don't end the mark.
     */
    public static function about(string $value): string
    {
        return '**'.str_replace('*', '∗', $value).'**';
    }
}
