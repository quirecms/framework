<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The editor as a manuscript: where the item will appear, its versions, the changes against what's
 * live, notes in the margin, and comments on the changes.
 */
final class ManuscriptTest extends AdminTestCase
{
    private const string BODY = "Sixteen teams, four groups.\n\nHosts open against Northside on Friday.\n";

    public function testWhatIsCommentedOnIsWhereTheManuscriptFillsItIn(): void
    {
        $grace = $this->createUser('Grace', ['editor']);
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->published()->create();
        $this->changes($post)->by($grace)->body(self::BODY.' More.')->create();
        $this->signIn($grace);

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);

        // Its controller only sees inside its element: the selection's quote and line go there, sent with the comment's form.
        foreach (['quote', 'line'] as $name) {
            self::assertCount(1, $crawler->filter('[data-controller="manuscript"] input[name="'.$name.'"][form="comment-new"][data-manuscript-target="'.$name.'"]'), $name);
        }
    }

    public function testWhereItWillAppear(): void
    {
        $this->setSetting('site.title', 'Tournament News');
        $this->signIn('editor');
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->excerpt('All sixteen teams.')->create();

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);
        self::assertStringContainsString('Spring draw', (string) $crawler->filter('.placement__card')->attr('srcdoc'), 'Its card, as the theme draws it.');
        self::assertSame('', $crawler->filter('.placement__card')->attr('sandbox'), 'Sandboxed: no scripts, no access to the admin.');
        self::assertSelectorTextSame('.placement__search-title', 'Spring draw | Tournament News');
        self::assertSelectorTextSame('.placement__description', 'All sixteen teams.');

        // As it's written: for what's in the form, saved or not.
        $this->client->request('POST', '/admin/content/post/placement', ['_token' => self::formToken($crawler), 'id' => $post->id, 'title' => 'Spring draw: all groups', 'slug' => '', 'body' => self::BODY, 'excerpt' => 'Groups A to D.']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.placement__search-title', 'Spring draw: all groups | Tournament News');
        self::assertSelectorTextSame('.placement__share-title', 'Spring draw: all groups');
        self::assertSelectorTextSame('.placement__description', 'Groups A to D.');
    }

    public function testTheChangesAgainstWhatsLiveAndTheVersions(): void
    {
        $member = $this->createUser('Grace', ['member', 'editor']);
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->excerpt('Short.')->published()->create();
        $this->changes($post)->by($member)->body(str_replace('Northside', 'Northside United', self::BODY))->create();
        $this->signIn($member);

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);

        self::assertSame(self::BODY, $crawler->filter('[data-markdown-editor-original-value]')->attr('data-markdown-editor-original-value'), 'Shown against the live text.');
        self::assertSelectorTextSame('.editor-bar__headline', 'Live · your changes aren\'t live yet');
        self::assertSelectorTextContains('.editor-versions__count', '1 version');
        self::assertCount(1, $crawler->filter('.editor-versions__dot--live'));
        self::assertStringContainsString('/history#revision-', (string) $crawler->filter('.editor-versions__dot--live')->attr('href'));
        self::assertCount(1, $crawler->filter('.editor-versions__dot--yours'), 'And the changes, not live yet.');
    }

    public function testWhatTheChecksFindIsInTheMargin(): void
    {
        $this->signIn('editor');
        $post = $this->content('post', 'Pictures')->body("Intro.\n\n![](/chart.png)\n")->create();

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);
        $notes = $crawler->filter('#checks .editor-note');

        self::assertSame(['', '3'], $notes->each(static fn (Crawler $note): string => (string) $note->attr('data-line')), 'Those about other parts first, then by line.');
        self::assertStringContainsString('excerpt', (string) $notes->first()->filter('a')->attr('href'));
        self::assertSelectorTextContains('.editor-bar__badge', '2 to check');
    }

    public function testCommentsOnTheChanges(): void
    {
        $grace = $this->createUser('Grace', ['editor']);
        $kim = $this->createUser('Kim', ['editor']);
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->excerpt('Short.')->published()->create();
        $pending = $this->changes($post)->by($grace)->body(str_replace('Northside', 'Northside United', self::BODY))->submitted()->create();
        $editor = '/admin/content/post/'.$post->id;
        $url = \sprintf('/admin/content/post/%s/changes/%s/comments', $post->id, $pending->id);

        // A reviewer comments on text of the changes, from the review.
        $this->signIn($kim);
        $crawler = $this->client->request('GET', $editor);
        $this->client->request('POST', $url, ['_token' => self::formToken($crawler), 'back' => $editor, 'message' => 'Is it "Northside United" now?', 'quote' => 'Northside United', 'line' => '3', 'property' => 'body']);
        self::assertResponseRedirects($editor, 303);
        [$thread] = $this->service(PendingRevisionComments::class)->threads($this->service(PendingRevisionRepository::class)->get($pending->id));

        // Its author sees it in the margin, at its line, and replies.
        $this->signIn($grace);
        $crawler = $this->client->request('GET', $editor);
        $note = $crawler->filter('.editor-note--comment');
        self::assertSame('3', $note->attr('data-line'));
        self::assertStringContainsString('“Northside United”', $note->text());
        self::assertStringContainsString('Is it "Northside United" now?', $note->text());

        $this->client->submit($crawler->filter(\sprintf('button[form="comment-%s"][value=reply]', $thread->comment->id))->form([$crawler->filter(\sprintf('#reply-%s', $thread->comment->id))->attr('name') ?? 'message' => 'Yes, since August.']));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Yes, since August.', $crawler->filter('.editor-note--comment')->text());

        $this->client->submit($crawler->filter(\sprintf('button[form="comment-%s"][value=resolve]', $thread->comment->id))->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.editor-note--resolved'));
        $this->assertAudited('content.comment_resolved');

        // Members don't use the admin, so they take no part.
        $this->signIn($this->createUser('Lin', ['member']));
        $this->client->request('POST', $url, ['message' => 'Me too.']);
        self::assertResponseStatusCodeSame(403);
    }
}
