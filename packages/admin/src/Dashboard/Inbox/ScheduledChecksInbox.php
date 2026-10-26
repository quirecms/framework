<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Inbox;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Dashboard\InboxItem;
use Quire\Admin\Dashboard\InboxProviderInterface;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Check\CheckResult;
use Quire\Content\Check\CheckResults;
use Quire\Content\Check\Level;
use Quire\Content\Content;
use Quire\Content\Status;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Items scheduled to go live within a week that the checks found something in: better fixed before.
 * Errors would stop them going live at all.
 *
 * @internal
 */
final readonly class ScheduledChecksInbox implements InboxProviderInterface
{
    private const string WITHIN = '+7 days';

    private const int LIMIT = 20;

    public function __construct(
        private ContentLists $lists,
        private CheckResults $checks,
        private RelativeTime $time,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function items(): iterable
    {
        foreach ($this->lists->listableTypes() as $type) {
            $items = $this->lists->query($type)->status(Status::SCHEDULED)->withIssues()
                ->where('published_at', '<=', new \DateTimeImmutable(self::WITHIN))
                ->orderBy('published_at')->limit(self::LIMIT)->get();
            $reports = $this->checks->forItems(array_map(static fn (Content $item): string => $item->id, [...$items]));

            foreach ($items as $item) {
                $results = $reports[$item->id]->results ?? [];

                if ([] === $results || null === $item->publishedAt) {
                    continue;
                }

                $errors = array_filter($results, static fn (CheckResult $result): bool => Level::Error === $result->level);
                yield new InboxItem(
                    key: 'scheduled-checks:'.$item->id,
                    text: $this->translator->trans('{title} goes live {when} with {count, plural, one {# thing} other {# things}} to check', ['title' => InboxItem::about($item->title), 'when' => $this->time->text($item->publishedAt), 'count' => \count($results)], 'quire-admin'),
                    url: $this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]).'#checks',
                    action: $this->translator->trans('Fix it', [], 'quire-admin'),
                    at: $item->publishedAt,
                    priority: [] === $errors ? 10 : 25,
                    stage: 'scheduled',
                    tags: [['label' => $this->short(([] === $errors ? $results[0] : array_first($errors))->finding->message), 'tone' => [] === $errors ? InboxItem::TONE_WARNING : InboxItem::TONE_DANGER]],
                    detail: [] === $errors ? null : $this->translator->trans('Errors stop it going live.', [], 'quire-admin'),
                );
            }
        }
    }

    /**
     * What was found, as a tag: its first sentence or clause, e.g. "An image has no description".
     */
    private function short(string $message): string
    {
        $parts = preg_split('/[:.;]\s/u', $message.' ');
        $first = trim(false === $parts ? $message : $parts[0], " .:\u{a0}");

        return mb_strlen($first) > 48 ? mb_substr($first, 0, 47).'…' : $first;
    }
}
