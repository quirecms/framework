<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use Quire\Content\ContentRepository;
use Quire\Testing\AdminTestCase;
use Quire\User\Role;
use Quire\User\UserRepository;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The redesigned admin end to end, as two people use it: an editor changes a published post and asks for
 * a review; the admin finds it waiting at home, reviews it side by side, approves and publishes it; says
 * what to do in the intent bar and sees the plan first; takes it back from the activity; changes a
 * setting in the settings document and moves the settings to another site; and keeps their own
 * preferences. Each thing done is recorded with the command that does the same.
 */
final class AdminRedesignTest extends AdminTestCase
{
    private const string BODY = "Sixteen teams, four groups.\n\nGroup D: TBC";

    public function testAnEditorAndAnAdminThroughTheRedesign(): void
    {
        $this->setSetting('quire.content.changes_need_approval', true);
        $grace = $this->createUser('Grace Hopper', [Role::EDITOR]);
        $ada = $this->createUser('Ada Lovelace', [Role::ADMIN]);
        $post = $this->content('post', 'Spring draw')->body(self::BODY)->excerpt('All groups.')->published()->create();

        // The editor changes what's live; visitors keep seeing it as it was until the changes are published.
        $this->signIn($grace);
        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);
        self::assertSelectorTextContains('.editor-bar__headline', 'Live');
        $this->client->submit($crawler->filter('button[name=intent][value=submit]')->form(['body' => str_replace('TBC', 'Eastfield, Port Rovers', self::BODY)]));
        self::assertSame(self::BODY, $this->service(ContentRepository::class)->find($post->id)?->body);

        // The admin finds it waiting at home, the one thing to do beside it.
        $this->signIn($ada);
        $crawler = $this->client->request('GET', '/admin');
        $waiting = $crawler->filter('.inbox__item')->reduce(static fn (Crawler $item): bool => str_contains($item->text(), 'Spring draw'));
        self::assertStringContainsString('Grace Hopper asks you to review their changes to', $waiting->text());
        self::assertGreaterThan(0, (int) $crawler->filter('.q-rail__badge')->text(), 'Counted beside Home.');

        // Side by side, then approved and published.
        $crawler = $this->client->click($waiting->selectLink('Review changes')->link());
        self::assertSame(['Removed: TBC', 'Added: Eastfield, Port Rovers'], $crawler->filter('.review-diff__row--change')->last()->filter('del, ins')->each(static fn (Crawler $mark): string => $mark->text()));
        $this->client->submit($crawler->selectButton('Approve')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Publish the changes')->form());
        self::assertStringEndsWith('Group D: Eastfield, Port Rovers', (string) $this->service(ContentRepository::class)->find($post->id)?->body);

        // The intent bar: the plan first, then done.
        $crawler = $this->client->request('GET', '/admin/find?q='.rawurlencode('suspend grace'));
        self::assertSame(['Suspend', 'Grace Hopper'], $crawler->filter('.intent-part')->each(static fn (Crawler $part): string => trim($part->text())));
        $this->client->submit($crawler->selectButton('Do it')->form());
        self::assertFalse($this->service(UserRepository::class)->find($grace->id)?->isActive());

        // The activity says it in words, with its command, and takes it back.
        $crawler = $this->client->request('GET', '/admin/audit');
        $card = $crawler->filter('.activity-card')->first();
        self::assertSame('Suspended Grace Hopper', $card->filter('.activity-card__what')->text());
        $this->client->submit($card->selectButton('Undo (Suspended Grace Hopper)')->form());
        self::assertTrue($this->service(UserRepository::class)->find($grace->id)?->isActive());

        foreach (['content.changes_submitted', 'content.changes_reviewed', 'content.changes_published', 'user.suspended', 'user.activated'] as $action) {
            self::assertNotNull($this->assertAudited($action)->command, $action.' has its command.');
        }

        // A setting changed in the document, and the settings moved to another site.
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->client->submit($crawler->filter('#edit-setting-site-title')->selectButton('Save')->form(['settings[site.title]' => 'Spring Cup']));
        $this->client->request('GET', '/admin/settings/export');
        self::assertStringContainsString("site.title: 'Spring Cup'", $this->client->getInternalResponse()->getContent());

        // Their own preferences, on every page.
        $crawler = $this->client->request('GET', '/admin');
        $this->client->request('POST', '/admin/preferences', ['_token' => (string) $crawler->filter('form.prefs input[name=_token]')->attr('value'), 'theme' => 'dark', 'back' => '/admin']);
        self::assertSame('dark', $this->client->request('GET', '/admin/audit')->filter('html')->attr('data-theme'));
    }
}
