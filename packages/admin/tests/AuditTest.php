<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Audit\AuditLog;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
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
        self::assertSelectorTextSame('title', 'Audit log · Admin · Quire');
        $rows = $crawler->filter('.audit tbody tr');
        $summaries = $rows->each(static fn (Crawler $row): string => $row->filter('.audit__summary')->text());
        self::assertEqualsCanonicalizing(['Published “Two”', 'Published “One”'], \array_slice($summaries, 0, 2), 'In the order the list did them.');
        self::assertSame(['Created “Two”', 'Created “One”', 'Admin signed in', 'Added the user Admin'], \array_slice($summaries, 2));
        self::assertSame('Admin Admin', $rows->first()->filter('td')->eq(1)->text(), 'Who, and where: in the admin.');
        self::assertContains($rows->first()->filter('a.audit__summary')->attr('href'), ['/admin/content/post/'.$one->id, '/admin/content/post/'.$two->id], 'What it was about, where it is now.');
        self::assertSame('Command line', $rows->eq(2)->filter('td')->eq(1)->text(), 'Created outside a request, as by a console command.');

        // Narrowed to what was done together: the bulk action.
        $crawler = $this->client->click($rows->first()->selectLink('Done together')->link());
        self::assertEqualsCanonicalizing(['Published “Two”', 'Published “One”'], $crawler->filter('a.audit__summary')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSelectorTextContains('.q-chips', 'Done together');

        // Narrowed by area and by who.
        $crawler = $this->client->request('GET', '/admin/audit?area=user.');
        self::assertSame(['Admin signed in', 'Added the user Admin'], $crawler->filter('.audit__summary')->each(static fn (Crawler $node): string => $node->text()));
        $crawler = $this->client->request('GET', '/admin/audit?actor='.$admin->id);
        self::assertSame(['Published', 'Published', 'Admin signed in'], $crawler->filter('.audit__summary')->each(static fn (Crawler $node): string => (string) preg_replace('/ “.*$/', '', $node->text())));
        self::assertSelectorTextContains('.q-chips', 'By Admin');
        self::assertSame('/admin/audit', $crawler->filter('.q-chips a')->attr('href'));
    }

    public function testDetailsCommandsAndLinksFromElsewhere(): void
    {
        $admin = $this->signIn('admin');
        $this->settings()->set('site.title', 'Audited');
        $this->service(AuditLog::class)->record('acme-shop.order_refunded', 'acme-shop.order', '1042', 'Order 1042', ['amount' => 12.5, 'lines' => ['a', 'b']]);

        $crawler = $this->client->request('GET', '/admin/audit');
        $setting = $crawler->filter('.audit tbody tr')->eq(1);
        self::assertSame('Changed the setting “Site title”', $setting->filter('a.audit__summary')->text());
        self::assertSame('/admin/settings/site', $setting->filter('a.audit__summary')->attr('href'));
        self::assertSame(['Before: Quire', 'After: Audited'], $setting->filter('.audit__details div')->each(static fn (Crawler $d): string => $d->filter('dt')->text().': '.$d->filter('dd')->text()));
        self::assertSame('bin/console quire:settings:set site.title Audited', $setting->filter('.audit__command .q-command__text')->text());

        $extension = $crawler->filter('.audit tbody tr')->first();
        self::assertSame('acme-shop.order_refunded: Order 1042', $extension->filter('.audit__summary')->text(), 'An action without a translation shows its name.');
        self::assertSame(['amount: 12.5', 'lines: a, b'], $extension->filter('.audit__details div')->each(static fn (Crawler $d): string => $d->filter('dt')->text().': '.$d->filter('dd')->text()));

        $crawler = $this->client->request('GET', '/admin/users/'.$admin->id);
        $crawler = $this->client->click($crawler->selectLink('What they did')->link());
        self::assertSelectorTextContains('.q-chips', 'By Admin');

        $crawler = $this->client->request('GET', '/admin');
        self::assertContains('Recent activity', $crawler->filter('.widgets .q-card h2')->each(static fn (Crawler $h2): string => $h2->text()));
    }

    private function post(string $title): Content
    {
        return $this->service(ContentWriter::class)->create(new CreateContent('post', $title));
    }
}
