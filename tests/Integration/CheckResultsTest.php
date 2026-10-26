<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Check\CheckResult;
use Quire\Content\Check\CheckResults;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Job\RecheckContent;
use Quire\Content\Check\Job\RecheckLinkingContent;
use Quire\Content\Check\Level;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the content checks find, stored for each item: kept up to date as content changes, read with the
 * site's current levels, and queryable.
 */
final class CheckResultsTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private CheckResults $results;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM quire.content');
        $this->connection->executeStatement('DELETE FROM quire.job');
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->results = self::getContainer()->get(CheckResults::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testSavingAnItemStoresWhatTheChecksFind(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Chart', body: "Intro.\n\n![](/chart.png)\n"));

        self::assertSame([['core.alt-text', 'warning', 'body', 3], ['core.excerpt', 'warning', 'excerpt', null]], $this->found($post));
        self::assertSame([$post->id => 1], $this->results->checkedVersions([$post->id]));

        $fixed = $this->writer->update(new UpdateContent($post->id, $post->version)->body("Intro.\n\n![A chart](/chart.png)\n")->excerpt('A chart.'));

        self::assertSame([], $this->found($fixed));
        self::assertSame([$post->id => 2], $this->results->checkedVersions([$post->id]));
    }

    public function testTheSitesLevelsApplyWhenResultsAreRead(): void
    {
        $chart = $this->writer->create(new CreateContent('post', 'Chart', body: '![](/chart.png)', excerpt: 'A chart.'));
        $plain = $this->writer->create(new CreateContent('post', 'Plain', body: 'Text.'));
        $this->writer->create(new CreateContent('post', 'Fine', body: 'Text.', excerpt: 'Fine.'));
        $posts = self::getContainer()->get(ContentRepository::class)->query('post')->withoutVisibilityScope();

        self::assertSame(['Chart', 'Plain'], $this->titles($posts->withIssues()->orderBy('title')->get()));
        self::assertSame([], $posts->withIssues(Level::Error)->get());
        self::assertSame(['core.alt-text' => 1, 'core.excerpt' => 1], $posts->countBy('checks'));

        // At once, without checking again.
        self::getContainer()->get(Settings::class)->set(ContentChecker::LEVELS_SETTING, ['core.alt-text' => 'error', 'core.excerpt' => 'off']);

        self::assertSame(['Chart'], $this->titles($posts->withIssues(Level::Error)->get()));
        self::assertSame(['Chart'], $this->titles($posts->withIssues()->get()), 'A check that\'s off doesn\'t count.');
        self::assertSame(['Chart'], $this->titles($posts->withIssues(check: 'core.alt-text')->get()));
        self::assertSame(['core.alt-text' => 1], $posts->countBy('checks'));
        self::assertSame([['core.alt-text', 'error', 'body', 1]], $this->found($chart));
        self::assertSame([], $this->found($plain));
        self::assertSame([], $this->queued(), 'Turning a check off or up needs no new check.');

        self::getContainer()->get(Settings::class)->set(ContentChecker::LEVELS_SETTING, ['core.alt-text' => 'error']);
        self::assertSame([RecheckContent::class], $this->queued(), 'Turned on, it runs for every item, in the background.');
    }

    public function testItemsAreCheckedAgainWhenWhatTheyLinkToChanges(): void
    {
        $target = $this->writer->create(new CreateContent('post', 'Rules', body: 'The rules.', excerpt: 'Rules.'));
        $target = $this->writer->transition($target->id, $target->version, Status::PUBLISH);
        $linking = $this->writer->create(new CreateContent('post', 'Preview', body: 'Read [the rules](/blog/rules) first.', excerpt: 'Preview.'));
        $other = $this->writer->create(new CreateContent('post', 'Elsewhere', body: 'No links.', excerpt: 'None.'));
        self::assertSame([], $this->found($linking));

        $this->connection->executeStatement('DELETE FROM quire.job');
        $this->writer->transition($target->id, $target->version, Status::UNPUBLISH);

        self::assertSame([RecheckLinkingContent::class], $this->queued());
        self::assertSame(1, $this->results->refreshLinkingTo(['rules'], $target->id), 'Only the items whose text has the slug.');
        self::assertSame([['core.links', 'warning', 'body', 1]], $this->found($linking));
        self::assertSame([], $this->found($other));
    }

    public function testEveryItemOrThoseThatChangedAreCheckedAgain(): void
    {
        $first = $this->writer->create(new CreateContent('post', 'First', body: 'Text.', excerpt: 'One.'));
        $second = $this->writer->create(new CreateContent('post', 'Second', body: 'Text.', excerpt: 'Two.'));
        // Saved behind Quire's back, e.g. by an import: its results are out of date.
        $this->connection->executeStatement('UPDATE quire.content SET excerpt = NULL, version = version + 1 WHERE id = ?', [$second->id]);

        self::assertSame(['checked' => 1, 'last' => null], $this->results->refreshAll(onlyChanged: true));
        self::assertSame([['core.excerpt', 'warning', 'excerpt', null]], $this->found($second));
        self::assertSame(['checked' => 2, 'last' => null], $this->results->refreshAll());

        $page = $this->results->refreshAll(batch: 1);
        self::assertSame(['checked' => 1, 'last' => min($first->id, $second->id)], $page);
        self::assertSame(['checked' => 1, 'last' => max($first->id, $second->id)], $this->results->refreshAll(after: $page['last'], batch: 1));
    }

    /**
     * @return list<array{string, string, ?string, ?int}>
     */
    private function found(Content $item): array
    {
        return array_map(static fn (CheckResult $result): array => [$result->check, $result->level->value, $result->finding->property, $result->finding->line], $this->results->for($item)->results);
    }

    /**
     * @param list<Content> $items
     *
     * @return list<string>
     */
    private function titles(array $items): array
    {
        return array_map(static fn (Content $item): string => $item->title, $items);
    }

    /**
     * @return list<string> The classes of the jobs waiting
     */
    private function queued(): array
    {
        $jobs = [];

        foreach ($this->connection->fetchFirstColumn('SELECT body FROM quire.job ORDER BY id') as $body) {
            \assert(\is_string($body));
            preg_match('/Quire\\\\\\\\Content\\\\\\\\Check\\\\\\\\Job\\\\\\\\(\w+)/', $body, $match);
            $jobs[] = isset($match[1]) ? 'Quire\\Content\\Check\\Job\\'.$match[1] : $body;
        }

        return $jobs;
    }
}
