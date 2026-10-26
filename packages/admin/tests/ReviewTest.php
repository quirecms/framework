<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Revision\CommentAnchor;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reviewing changes: what's live and the changes side by side, the conversation about them, and what
 * the checks found in them.
 */
final class ReviewTest extends AdminTestCase
{
    private const string BODY = "Sixteen teams, four groups.\n\n![The stage](/stage.jpg)\n\nHosts open against Northside on Friday.\n\nGroup D: TBC\n";

    public function testTheChangesSideBySideWithTheConversation(): void
    {
        $grace = $this->createUser('Grace Hopper', ['editor']);
        $ada = $this->createUser('Ada Lovelace', ['editor']);
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->excerpt('All groups.')->published()->create();
        $pending = $this->changes($post)->by($grace)->message('Group D is confirmed.')
            ->body(str_replace(['Northside', 'TBC'], ['Northside United', 'Eastfield, Port Rovers'], self::BODY))
            ->title('Spring draw: all groups')
            ->submitted()->commentedBy($ada, 'Looks right.')->create();
        $this->service(PendingRevisionComments::class)->add($pending->id, 'Full name now?', $ada->id, new CommentAnchor('body', 'Northside United', 5));
        $url = \sprintf('/admin/content/post/%s/changes/%s', $post->id, $pending->id);

        $this->signIn($ada);
        $crawler = $this->client->request('GET', $url);

        self::assertSelectorTextSame('h1', 'Grace Hopper’s changes to “Spring draw”');
        self::assertSelectorTextContains('.review-bar__detail', 'checks pass');
        self::assertSame(['Grace Hopper’s version'], $crawler->filter('.review-diff__table th')->eq(1)->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(['Removed: TBC', 'Added: Eastfield, Port Rovers'], $crawler->filter('.review-diff__row--change')->last()->filter('del, ins')->each(static fn (Crawler $mark): string => $mark->text()));
        self::assertStringContainsString('Title', $crawler->filter('.review-diff__also summary')->text(), 'Other changes, below the text.');

        // Its author's message, then the reviews and comments, in order.
        self::assertSame(
            ['Grace Hopper Group D is confirmed.', 'Ada Lovelace commented Looks right.', 'Ada Lovelace Full name now?'],
            $crawler->filter('.conversation__entry')->each(static fn (Crawler $entry): string => trim(str_replace($entry->filter('.conversation__who .q-meta')->text(), '', $entry->filter('.conversation__who')->text())).' '.$entry->filter('.conversation__body')->text()),
        );
        self::assertSelectorTextContains('.conversation__quote', '“Northside United” line 5');

        // A comment, sent on its own.
        $this->client->submit($crawler->selectButton('Send')->last()->form(['message' => 'One more look after lunch.']));
        self::assertResponseRedirects($url, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('One more look after lunch.', $crawler->filter('.conversation')->text());

        // Its author replies there and resolves it.
        $this->signIn($grace);
        $crawler = $this->client->request('GET', $url);
        $thread = $crawler->filter('.conversation__thread')->first();
        $this->client->submit($thread->selectButton('Send')->form(['message' => 'Yes, since August.']));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Yes, since August.', $crawler->filter('.conversation__thread')->first()->text());
        $this->client->submit($crawler->filter('.conversation__thread')->first()->selectButton('Resolve')->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.conversation__thread--resolved'));
        self::assertCount(0, $crawler->filter('.review-bar button[value=approve]'), 'Nobody reviews their own changes here.');
    }

    public function testWhatTheChecksFindAndWhetherTheChangesDidIt(): void
    {
        $this->signIn('editor');
        $post = $this->content('post', 'Pictures')->body("Intro.\n\n![](/chart.png)\n")->excerpt('Short.')->published()->create();
        $pending = $this->changes($post)->body("Intro.\n\n![](/chart.png)\n\nSee [the rules](/blog/rules-2019).\n")->create();

        $crawler = $this->client->request('GET', \sprintf('/admin/content/post/%s/changes/%s', $post->id, $pending->id));

        self::assertSelectorTextContains('.review-bar__detail', '1 check to look at');
        $found = $crawler->filter('.review-checks li:not(.review-checks__pass)')->each(static fn (Crawler $li): string => $li->text());
        self::assertCount(2, $found);
        self::assertStringContainsString('(not part of these changes)', implode("\n", array_filter($found, static fn (string $text): bool => str_contains($text, 'description'))));
        self::assertStringNotContainsString('(not part of these changes)', implode("\n", array_filter($found, static fn (string $text): bool => str_contains($text, 'rules-2019'))));
        self::assertNotEmpty($crawler->filter('.review-checks__pass'), 'And what passed.');
    }
}
