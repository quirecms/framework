<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Content;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "In motion": what's moving through the lifecycle, as columns: drafts, changes in review, what's
 * scheduled, and what went live this week. Each column has its count and its latest items; the list,
 * narrowed to the column, has the rest. The list's other filters apply.
 *
 * @internal
 *
 * @phpstan-type Column array{id: string, label: string, stage: string, count: int, items: list<Content>}
 */
final readonly class ContentBoard
{
    public const int CARDS = 8;

    public function __construct(
        private ContentLists $lists,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<Column>
     */
    public function columns(ContentTypeDefinition $type, ListState $state): array
    {
        $base = $this->lists->filtered($type, $state, except: 'status');

        if ($type->has('author')) {
            $base = $base->select('author_name', 'SELECT u.display_name FROM quire.app_user u WHERE u.id = c.author_id');
        }

        $columns = [
            ['draft', $this->lists->statusLabel(Status::DRAFT), 'draft', $this->lists->narrow($type, $base, 'status', [Status::DRAFT])->orderBy('updated_at', 'desc')],
            ['review', $this->translator->trans('In review', [], 'quire-admin'), 'review', $this->lists->narrow($type, $base, 'status', ['review'])->orderBy('updated_at', 'desc')],
            ['scheduled', $this->lists->statusLabel(Status::SCHEDULED), 'scheduled', $this->lists->narrow($type, $base, 'status', [Status::SCHEDULED])->orderBy('published_at', 'asc')],
            ['live', $this->translator->trans('Live this week', [], 'quire-admin'), 'live', $this->lists->narrow($type, $base, 'status', [Status::PUBLISHED])->where('published_at', '>=', new \DateTimeImmutable('-7 days'))->orderBy('published_at', 'desc')],
        ];

        return array_map(static fn (array $column): array => [
            'id' => $column[0],
            'label' => $column[1],
            'stage' => $column[2],
            'count' => $column[3]->count(),
            'items' => $column[3]->limit(self::CARDS)->get(),
        ], $columns);
    }
}
