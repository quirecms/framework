<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Admin\Content\ListState;
use Symfony\Component\HttpFoundation\Request;

final class ListStateTest extends TestCase
{
    private const array FACETS = ['status', 'checks', 'locale', 'fields.tags'];

    private const array ALIASES = ['tag' => 'fields.tags', 'check' => 'checks', 'lang' => 'locale'];

    public function testTheUrlSaysWhatTheListShows(): void
    {
        $state = ListState::fromRequest(Request::create('/', 'GET', [
            'view' => 'mine', 'mode' => 'calendar', 'month' => '2026-10', 'q' => 'draw', 'sort' => '-title', 'page' => '2',
            'f' => ['status' => ['draft', '', 'draft'], 'fields.tags' => 'php', 'fields.secret' => ['x']],
            'filter' => ['acme' => 'yes', 'other' => 'no'],
        ]), self::FACETS, ['acme']);

        self::assertSame(['mine', 'calendar', '2026-10', 'draw', '-title', 2], [$state->view, $state->mode, $state->month, $state->q, $state->sort, $state->page]);
        self::assertSame(['status' => ['draft'], 'fields.tags' => ['php']], $state->facets, 'Known facets only, without empty or repeated values.');
        self::assertSame(['acme' => 'yes'], $state->filters);
        self::assertSame(['view' => 'mine', 'mode' => 'calendar', 'q' => 'draw', 'f' => ['status' => ['draft'], 'fields.tags' => ['php']], 'filter' => ['acme' => 'yes'], 'sort' => '-title', 'page' => 2, 'month' => '2026-10'], $state->toQuery());

        $unknown = ListState::fromRequest(Request::create('/', 'GET', ['view' => 'nope', 'mode' => 'map', 'month' => '2026-13', 'page' => '-3']), self::FACETS, []);
        self::assertSame(['all', 'list', null, 1], [$unknown->view, $unknown->mode, $unknown->month, $unknown->page]);
        self::assertSame([], $unknown->toQuery(), 'Defaults are left out of URLs.');
    }

    public function testSearchWordsChooseFacets(): void
    {
        self::assertSame(['draw results', ['fields.tags' => ['draw', 'group stage'], 'status' => ['draft'], 'checks' => ['any']]], ListState::parse('draw tag:draw status:draft results tag:"group stage" check:any', self::FACETS, self::ALIASES));
        self::assertSame(['colour:red time:12:00', []], ListState::parse('colour:red time:12:00', self::FACETS, self::ALIASES), 'Words of no facet are searched for.');

        $state = ListState::fromRequest(Request::create('/', 'GET', ['q' => 'lang:de tag:php', 'f' => ['fields.tags' => ['cms']]]), self::FACETS, [], self::ALIASES);
        self::assertSame(['fields.tags' => ['cms', 'php'], 'locale' => ['de']], $state->facets);
        self::assertSame('tag:cms tag:php lang:de', $state->syntax(self::ALIASES), 'As "Under the hood" shows them.');
    }

    public function testChoosingAValueGoesBackToTheFirstPage(): void
    {
        $state = new ListState(page: 3, facets: ['status' => ['draft']]);

        self::assertSame(['status' => ['draft', 'published']], $state->toggled('status', 'published')->facets);
        self::assertSame([], $state->toggled('status', 'draft')->facets, 'Again, and it\'s not chosen.');
        self::assertSame(1, $state->toggled('status', 'published')->page);
        self::assertTrue($state->isFiltered());
    }
}
