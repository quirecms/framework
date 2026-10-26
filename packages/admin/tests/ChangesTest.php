<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Testing\AdminTestCase;
use Quire\User\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Changes to published items: saved as the editor's pending revision, published by hand or at a time,
 * reviewed, merged with what was published meanwhile; and the items' history.
 */
final class ChangesTest extends AdminTestCase
{
    private const string BODY = "# Opening hours\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n";

    public function testChangesToAPublishedItemWaitUntilTheyArePublished(): void
    {
        $this->signIn('editor');
        $post = $this->published();

        $crawler = $this->client->request('GET', $this->editor($post));
        self::assertSelectorTextSame('.editor-bar__headline', 'Live · what you see is what visitors see');
        $this->client->submit($this->button($crawler, 'save'), ['title' => 'Opening times']);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextSame('.q-notice--success', 'Your changes are saved. Visitors see them once they\'re published.');
        self::assertInputValueSame('title', 'Opening times', 'The editor shows the changes.');
        self::assertSame('Hours', $this->live($post)->title, 'Visitors still see the published text.');
        self::assertSelectorTextContains('.content-list__table, body', '');
        $list = $this->client->request('GET', '/admin/content/post');
        self::assertStringContainsString('changes waiting', $list->filter('tbody tr[data-content-list-target=row]')->first()->text());

        $crawler = $this->client->request('GET', $this->editor($post));
        $this->client->submit($this->button($crawler, 'publish_changes'));
        $this->client->followRedirect();

        self::assertSelectorTextSame('.q-notice--success', 'Your changes are published.');
        self::assertSame('Opening times', $this->live($post)->title);
        self::assertSame([], $this->pendingOf($post));
    }

    public function testAutosaving(): void
    {
        $this->signIn('editor');
        $post = $this->published();
        $crawler = $this->client->request('GET', $this->editor($post));
        $form = $this->button($crawler, 'save');
        $values = $form->getPhpValues();

        $this->client->request('POST', $form->getUri(), ['intent' => 'autosave', 'title' => 'Typed so far'] + $values, server: ['HTTP_ACCEPT' => 'application/json']);

        $saved = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($saved);
        self::assertSame(2, $saved['pending_version'], 'Created, then saved.');
        self::assertIsString($saved['saved_at'] ?? null);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $saved['saved_at']);
        self::assertSame('Typed so far', $this->pendingOf($post)[0]->proposed->title);
        self::assertSame('Hours', $this->live($post)->title);
    }

    public function testMembersWriteChangesAndEditorsReviewAndPublishThem(): void
    {
        $member = $this->signIn('member');
        $this->service(Permissions::class)->grant('member', ['quire.admin.access', 'content.post.edit_own']);
        $post = $this->published($member);

        $crawler = $this->client->request('GET', $this->editor($post));
        self::assertCount(0, $crawler->filter('button[value=publish_changes]'), 'Publishing takes the right to publish.');
        self::assertCount(0, $crawler->filter('#publish_at'));
        self::assertNotNull($crawler->filter('fieldset.editor-panel__group')->last()->attr('disabled'), 'The address changes the item right away.');

        $this->client->submit($this->button($crawler, 'submit'), ['body' => str_replace('9 to 5', '9 to 6', self::BODY)]);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Your changes are waiting for a review.');
        $pending = $this->pendingOf($post)[0];
        self::assertSame(ReviewState::InReview, $pending->state);

        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');
        $item = $crawler->filter('.inbox__item')->reduce(static fn (Crawler $item): bool => str_contains($item->text(), 'Hours'));
        self::assertSame('Member asks you to review their changes to Hours', $item->filter('.inbox__text')->text());
        $crawler = $this->client->click($item->selectLink('Review changes')->link());

        self::assertSelectorTextSame('h1', 'Member’s changes to “Hours”');
        self::assertSelectorTextContains('.review-bar__detail', 'merges cleanly with what’s live');
        self::assertSame([['Monday to Friday, 9 to Removed: 5.', 'Monday to Friday, 9 to Added: 6.']], $this->changedLines($crawler), 'Side by side, the changed word marked.');

        $this->client->submit($crawler->selectButton('Approve')->form(['message' => 'Good catch.']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'The changes are approved.');
        self::assertSame(['Editor approved Good catch.'], $crawler->filter('.conversation__entry')->each(static fn (Crawler $li): string => trim((string) preg_replace('/\s+/', ' ', $li->filter('strong')->text().' approved '.$li->filter('.conversation__body')->text()))));
        self::assertStringContainsString('approved', $crawler->filter('.conversation__who')->text());

        $this->client->submit($crawler->selectButton('Publish the changes')->form());
        self::assertSame(str_replace('9 to 5', '9 to 6', self::BODY), $this->live($post)->body);
    }

    public function testWhenTheSiteAsksForApprovalNobodyApprovesTheirOwnChanges(): void
    {
        $this->setSetting('quire.content.changes_need_approval', true);
        $this->signIn('editor');
        $post = $this->published();

        $crawler = $this->client->request('GET', $this->editor($post));
        $this->client->submit($this->button($crawler, 'save'), ['title' => 'Unapproved']);
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('button[value=publish_changes]'));
        self::assertSelectorTextContains('.editor-popover', 'approved by a reviewer');

        $crawler = $this->client->click($crawler->selectLink('Show changes and reviews')->link());
        self::assertCount(0, $crawler->filter('button[value=approve]'));
        self::assertSelectorTextSame('.review-form .q-help', 'Someone else approves your own changes.');

        $token = (string) $crawler->filter('.review-form input[name=_token]')->attr('value');
        $this->client->request('POST', $crawler->filter('.review-form')->form()->getUri(), ['_token' => $token, 'intent' => 'approve', 'version' => $this->pendingOf($post)[0]->version]);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--error', 'You may not review these changes.');
        self::assertSame(ReviewState::Editing, $this->pendingOf($post)[0]->state);
    }

    public function testConflictsWithChangesPublishedMeanwhileAreResolvedByKeepingMine(): void
    {
        $this->signIn('editor');
        $post = $this->published();
        $crawler = $this->client->request('GET', $this->editor($post));
        $this->client->submit($this->button($crawler, 'save'), ['body' => str_replace('9 to 5', '9 to 6', self::BODY)]);

        // Someone else publishes a change to the same line, and one to another.
        $live = $this->live($post);
        $this->service(ContentWriter::class)->update(new UpdateContent($live->id, $live->version)->body(str_replace(['9 to 5', 'Sundays'], ['10 to 5', 'Sundays and holidays'], self::BODY)));

        $crawler = $this->client->request('GET', $this->editor($post));
        $crawler = $this->client->submit($this->button($crawler, 'publish_changes'));
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.q-notice--warning', 'conflict with yours');
        self::assertSame(['Body'], $crawler->filter('.q-notice--warning li')->each(static fn (Crawler $li): string => $li->text()));

        $this->client->submit($crawler->selectButton('Keep my version')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($this->button($crawler, 'publish_changes'));

        self::assertSame("# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays and holidays.\n", $this->live($post)->body, 'Mine where they conflict, theirs elsewhere.');
    }

    public function testSchedulingChanges(): void
    {
        $this->setSetting('site.timezone', 'Europe/Berlin');
        $this->signIn('editor');
        $post = $this->published();
        $crawler = $this->client->request('GET', $this->editor($post));

        $this->client->submit($this->button($crawler, 'schedule_changes'), ['title' => 'Later', 'publish_at' => '2030-01-02T09:30']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Your changes will be published on 2030-01-02 09:30.');
        self::assertSame('2030-01-02T08:30:00+00:00', $this->pendingOf($post)[0]->publishAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));

        $this->client->submit($this->button($crawler, 'unschedule_changes'));
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Your changes are no longer scheduled.');
        self::assertNull($this->pendingOf($post)[0]->publishAt);
    }

    public function testDiscardingChanges(): void
    {
        $this->signIn('editor');
        $post = $this->published();
        $crawler = $this->client->request('GET', $this->editor($post));
        $this->client->submit($this->button($crawler, 'save'), ['title' => 'Never mind']);
        $crawler = $this->client->followRedirect();

        $this->client->submit($this->button($crawler, 'discard'));
        $this->client->followRedirect();

        self::assertSame([], $this->pendingOf($post));
        self::assertInputValueSame('title', 'Hours');
    }

    public function testHistoryAndRestoringAVersion(): void
    {
        $this->signIn('editor');
        $writer = $this->service(ContentWriter::class);
        $draft = $writer->create(new CreateContent('post', 'First title'));
        $draft = $writer->update(new UpdateContent($draft->id, $draft->version)->title('Second title'));

        $crawler = $this->client->request('GET', '/admin/content/post/'.$draft->id.'/history');
        self::assertSelectorTextSame('h1', 'History of “Second title”');
        self::assertCount(2, $crawler->filter('.history > li'));
        self::assertSame([['Title', 'First title', 'Second title']], $crawler->filter('.history > li')->first()->filter('.diff-properties tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('th, td')->each(static fn (Crawler $cell): string => $cell->text())));

        // A draft: restored right away.
        $this->client->submit($crawler->selectButton('Restore this version')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'The old version is restored.');
        self::assertSame('First title', $this->live($draft)->title);

        // A published item: restored as the user's changes, to publish.
        $published = $this->published();
        $published = $writer->update(new UpdateContent($published->id, $published->version)->title('Renamed'));
        $crawler = $this->client->request('GET', '/admin/content/post/'.$published->id.'/history');
        $this->client->submit($crawler->selectButton('Restore as your changes')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'The old version is in your changes now. Publish them to restore it.');
        self::assertInputValueSame('title', 'Hours');
        self::assertSame('Renamed', $this->live($published)->title);
    }

    public function testTheAddressOfAPublishedItemChangesRightAway(): void
    {
        $this->signIn('editor');
        $post = $this->published();
        $crawler = $this->client->request('GET', $this->editor($post));

        $this->client->submit($crawler->filter('#place-form')->form(['slug' => 'opening-hours']));
        $this->client->followRedirect();

        self::assertSame('opening-hours', $this->live($post)->slug);
        self::assertSame([], $this->pendingOf($post), 'Not a change to publish.');
    }

    private function published(?User $author = null): Content
    {
        $writer = $this->service(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Hours', body: self::BODY, authorId: $author?->id));

        return $writer->transition($post->id, $post->version, Status::PUBLISH);
    }

    private function editor(Content $item): string
    {
        return '/admin/content/post/'.$item->id;
    }

    private function button(Crawler $crawler, string $intent): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter(\sprintf('button[name=intent][value="%s"]', $intent))->form();
    }

    private function live(Content $item): Content
    {
        return $this->service(ContentRepository::class)->get($item->id);
    }

    /**
     * @return list<PendingRevision>
     */
    private function pendingOf(Content $item): array
    {
        return $this->service(PendingRevisionRepository::class)->forContent($item->id);
    }

    /**
     * @return list<list<string>>
     */
    private function changedLines(Crawler $crawler): array
    {
        return $crawler->filter('.review-diff__row--change')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => $td->text()));
    }
}
