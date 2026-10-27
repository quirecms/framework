<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Audit\AuditLog;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\CommentAnchor;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Status;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AuditTest extends AdminTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Tests that commit leave entries behind; this one's transaction is rolled back.
        $this->connection()->executeStatement('DELETE FROM quire.audit_entry');
    }

    public function testOnlyThoseWhoMaySeeTheAuditLog(): void
    {
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(0, $crawler->filter('nav a[href="/admin/audit"]'));
        self::assertNotContains('Recent activity', $crawler->filter('.widgets .q-card h2')->each(static fn (Crawler $h2): string => $h2->text()));

        $this->client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(403);

        // Editors don't run console commands: no command after their actions either.
        $post = $this->post('Mine');
        $crawler = $this->client->request('GET', '/admin/content/post');
        $this->client->request('POST', '/admin/content/post', ['_token' => (string) $crawler->filter('form.content-list input[name=_token]')->attr('value'), 'row' => 'transition:publish|'.$post->id]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.q-notice--success');
        self::assertCount(0, $crawler->filter('.q-command'));
    }

    public function testTheLogSaysWhoDidWhatWhereAndHow(): void
    {
        $admin = $this->signIn('admin');
        $one = $this->post('One');
        $two = $this->post('Two');

        // A bulk action: one request, two entries, and the commands of both shown after it.
        $crawler = $this->client->request('GET', '/admin/content/post');
        $this->client->request('POST', '/admin/content/post', ['_token' => (string) $crawler->filter('form.content-list input[name=_token]')->attr('value'), 'action' => 'transition:publish', 'ids' => [$one->id, $two->id]]);
        $crawler = $this->client->followRedirect();
        self::assertEqualsCanonicalizing(["bin/console quire:content:transition {$one->id} publish", "bin/console quire:content:transition {$two->id} publish"], explode("\n", $crawler->filter('.q-command__text')->text(normalizeWhitespace: false)));
        self::assertSelectorTextSame('.q-command__label', 'As a command');

        $crawler = $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Activity · Admin · Quire');
        self::assertSelectorTextSame('h1', 'Who did what');
        $cards = $crawler->filter('.activity-card');
        self::assertSame(['Published “One” and 1 more at once', 'Created “Two” and 1 more at once', 'Admin signed in', 'Added the user Admin'], $cards->each(static fn (Crawler $card): string => $card->filter('.activity-card__what')->text()), 'What was done together, folded.');
        self::assertEqualsCanonicalizing(['Published “One”', 'Published “Two”'], $cards->first()->filter('.activity-card__all li > a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertContains($cards->first()->filter('.activity-card__all a')->attr('href'), ['/admin/content/post/'.$one->id, '/admin/content/post/'.$two->id], 'What it was about, where it is now.');
        self::assertStringStartsWith('Admin · Admin', $cards->first()->filter('.activity-card__meta')->text(), 'Who, and where: in the admin.');
        self::assertStringStartsWith('Command line', $cards->eq(1)->filter('.activity-card__meta')->text(), 'Created outside a request, as by a console command.');
        self::assertStringContainsString('Today · 6 actions', $crawler->filter('.activity-day__title')->text());
        self::assertSame(6, array_sum($crawler->filter('.activity-days__bar')->each(static fn (Crawler $bar): int => (int) preg_replace('/\D+/', '', explode(':', $bar->text())[1] ?? ''))), 'The bars count them by day.');

        // Narrowed to what was done together: the bulk action, from under the hood.
        $crawler = $this->client->click($cards->first()->selectLink('Done together')->link());
        self::assertSame(['Published “One” and 1 more at once'], $crawler->filter('.activity-card__what')->each(static fn (Crawler $p): string => $p->text()));
        self::assertSelectorTextContains('.q-chips', 'Done together');

        // Narrowed by area, by who, and by words.
        $crawler = $this->client->request('GET', '/admin/audit?area=user.');
        self::assertSame(['Admin signed in', 'Added the user Admin'], $crawler->filter('.activity-card__what')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSame(['Everyone', 'People'], $crawler->filter('.activity-chips a[aria-current]')->each(static fn (Crawler $a): string => $a->text()));
        $crawler = $this->client->request('GET', '/admin/audit?actor='.$admin->id);
        self::assertSame(['Published “One” and 1 more at once', 'Admin signed in'], $crawler->filter('.activity-card__what')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSelectorTextSame('.activity-chips a[aria-current]', 'Me');
        $crawler = $this->client->request('GET', '/admin/audit?q=published');
        self::assertSame(['Published “One” and 1 more at once'], $crawler->filter('.activity-card__what')->each(static fn (Crawler $node): string => $node->text()), 'In the words the admin says it.');
        $crawler = $this->client->request('GET', '/admin/audit?q=two');
        self::assertSame(['Published “Two”', 'Created “Two”'], $crawler->filter('.activity-card__what')->each(static fn (Crawler $node): string => $node->text()), 'And what it\'s about.');
    }

    public function testCommentsOnChangesAreSaidInWords(): void
    {
        $admin = $this->signIn('admin');
        $post = $this->content('post', 'Spring draw')->body('Hosts open on Friday.')->published()->create();
        $pending = $this->changes($post)->by($admin)->body('Hosts open on Saturday.')->create();
        $comments = $this->service(PendingRevisionComments::class);
        $comment = $comments->add($pending->id, 'Which day?', $admin->id, new CommentAnchor('body', 'Saturday', 1));
        $comments->resolve($comment->id, $admin->id);

        $crawler = $this->client->request('GET', '/admin/audit');
        $said = $crawler->filter('.activity-card__what')->each(static fn (Crawler $what): string => $what->text());

        self::assertContains('Commented on changes to “Spring draw”', $said, 'Not its key, with the detail "on" put into it.');
        self::assertContains('Resolved a comment on changes to “Spring draw”', $said);
    }

    public function testUndoingFromTheActivity(): void
    {
        $this->signIn('admin');
        [$one, $two] = [$this->post('One'), $this->post('Two')];
        $crawler = $this->client->request('GET', '/admin/content/post');
        $this->client->request('POST', '/admin/content/post', ['_token' => (string) $crawler->filter('form.content-list input[name=_token]')->attr('value'), 'action' => 'transition:publish', 'ids' => [$one->id, $two->id]]);

        $crawler = $this->client->request('GET', '/admin/audit');
        self::assertCount(1, $crawler->filter('.activity-card button')->reduce(static fn (Crawler $button): bool => str_starts_with($button->text(), 'Undo')), 'Only what can be undone: not creating, not signing in.');
        $this->client->submit($crawler->filter('.activity-card')->first()->selectButton('Undo (Published “One”)')->form());
        self::assertResponseRedirects('/admin/audit', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', '2 undone.');
        self::assertSame([Status::DRAFT, Status::DRAFT], [$this->service(ContentRepository::class)->find($one->id)?->status, $this->service(ContentRepository::class)->find($two->id)?->status]);
        self::assertStringStartsWith('Unpublished', $crawler->filter('.activity-card__what')->first()->text(), 'Recorded like any action.');
        self::assertCount(0, $crawler->filter('.activity-card')->eq(1)->filter('input[name="entries[]"]'), 'Undone once.');
    }

    public function testExportingWhatIsShown(): void
    {
        $this->signIn('admin');
        $this->post('One');
        $crawler = $this->client->request('GET', '/admin/audit?area=content.');
        self::assertSame('/admin/audit/export?area=content.', $crawler->selectLink('Export')->attr('href'), 'Under the hood, which is on for admins.');

        $this->client->request('GET', '/admin/audit/export?area=content.');
        $content = $this->client->getInternalResponse()->getContent();
        self::assertResponseHeaderSame('Content-Type', 'application/x-ndjson; charset=utf-8');
        $lines = array_values(array_filter(explode("\n", $content), static fn (string $line): bool => '' !== $line));
        self::assertCount(1, $lines);
        $line = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        self::assertSame('content.created', $line['action'] ?? null);
    }

    public function testDetailsCommandsAndLinksFromElsewhere(): void
    {
        $admin = $this->signIn('admin');
        $this->settings()->set('site.title', 'Audited');
        $this->service(AuditLog::class)->record('acme-shop.order_refunded', 'acme-shop.order', '1042', 'Order 1042', ['amount' => 12.5, 'lines' => ['a', 'b']]);

        $crawler = $this->client->request('GET', '/admin/audit');
        $setting = $crawler->filter('.activity-card')->eq(1);
        self::assertSame('Changed the setting “Site title”', $setting->filter('.activity-card__what a')->text());
        self::assertSame('/admin/settings/site', $setting->filter('.activity-card__what a')->attr('href'));
        self::assertSame(['Before: Quire', 'After: Audited'], $setting->filter('.activity-card__details div')->each(static fn (Crawler $d): string => $d->filter('dt')->text().': '.$d->filter('dd')->text()));
        self::assertSame('bin/console quire:settings:set site.title Audited', $setting->filter('.q-command__text')->text());
        self::assertCount(1, $setting->filter('input[name="entries[]"]'), 'It can be undone.');

        $extension = $crawler->filter('.activity-card')->first();
        self::assertSame('acme-shop.order_refunded: Order 1042', $extension->filter('.activity-card__what')->text(), 'An action without a translation shows its name.');
        self::assertSame(['amount: 12.5', 'lines: a, b'], $extension->filter('.activity-card__details div')->each(static fn (Crawler $d): string => $d->filter('dt')->text().': '.$d->filter('dd')->text()));

        $crawler = $this->client->request('GET', '/admin/users/'.$admin->id);
        $crawler = $this->client->click($crawler->selectLink('What they did')->link());
        self::assertSelectorTextSame('.activity-chips a[aria-current]', 'Me');
        $kim = $this->createUser('Kim Ono', ['editor']);
        $this->client->request('GET', '/admin/audit?actor='.$kim->id);
        self::assertSelectorTextContains('.q-chips', 'By Kim Ono');

        $this->content('post', 'Live')->published()->create();
        $crawler = $this->client->request('GET', '/admin');
        self::assertContains('Latest', $crawler->filter('.home-card h2')->each(static fn (Crawler $h2): string => $h2->text()));
    }

    private function post(string $title): Content
    {
        return $this->service(ContentWriter::class)->create(new CreateContent('post', $title));
    }
}
