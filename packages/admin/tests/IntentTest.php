<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\ContentRepository;
use Quire\Content\Status;
use Quire\Testing\AdminTestCase;
use Quire\User\Role;
use Quire\User\UserRepository;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The intent bar: finding what the words name, and doing what they ask, as a plan first.
 */
final class IntentTest extends AdminTestCase
{
    public function testItFindsContentPeopleSettingsAndPages(): void
    {
        $this->signIn('admin');
        $this->content('post', 'Spring draw: all groups')->body('Sixteen teams.')->published()->create();
        $this->createUser('Grace Hopper', [Role::EDITOR]);

        $crawler = $this->client->request('GET', '/admin/find?q=spring');
        self::assertSelectorTextContains('#found-content + ul', 'Spring draw: all groups');
        self::assertSame([], $crawler->filter('.intent-plan')->each(static fn (Crawler $plan): string => $plan->text()), 'Words, not an intent.');

        self::assertStringContainsString('Grace Hopper', $this->client->request('GET', '/admin/find?q=grace')->filter('#found-people + ul')->text());
        self::assertStringContainsString('Time zone', $this->client->request('GET', '/admin/find?q=time zone')->filter('#found-settings + ul')->text());
        self::assertStringContainsString('Roles', $this->client->request('GET', '/admin/find?q=roles')->filter('#found-pages + ul')->text());

        // As the bar asks for it, as you type: the results only.
        $this->client->request('GET', '/admin/find?q=spring', server: ['HTTP_X_QUIRE_REQUEST' => 'fetch']);
        self::assertStringStartsWith('<div class="intent-results"', trim((string) $this->client->getResponse()->getContent()));
    }

    public function testWhatTheWordsAskIsAPlanFirst(): void
    {
        $this->setSetting('site.timezone', 'Europe/Skopje');
        $this->signIn('admin');
        $post = $this->content('post', 'Spring draw: all groups')->body('Sixteen teams.')->create();

        $crawler = $this->client->request('GET', '/admin/find?q='.rawurlencode('schedule spring draw friday 18:00'));
        $parts = $crawler->filter('.intent-part')->each(static fn (Crawler $part): string => trim($part->text()));
        self::assertSame(['Schedule', 'Spring draw: all groups'], \array_slice($parts, 0, 2));
        self::assertStringEndsWith('18:00 · Europe/Skopje', $parts[2]);
        self::assertStringStartsWith('“Spring draw: all groups” goes live by itself on', $crawler->filter('.intent-steps li')->text());
        self::assertStringContainsString('bin/console quire:content:transition '.$post->id.' schedule --at=', $crawler->filter('.intent-command')->text(), 'Under the hood, the command.');
        self::assertSame(Status::DRAFT, $this->service(ContentRepository::class)->find($post->id)?->status, 'Nothing happened yet.');

        $this->client->submit($crawler->selectButton('Do it')->form());
        self::assertResponseRedirects('/admin/content/post/'.$post->id, 303);
        self::assertSame(Status::SCHEDULED, $this->service(ContentRepository::class)->find($post->id)?->status);
        $this->assertAudited('content.transitioned', $post->id);

        // What can't be done says why, and isn't offered.
        $crawler = $this->client->request('GET', '/admin/find?q='.rawurlencode('schedule spring draw'));
        self::assertSelectorTextContains('.intent-denied', 'Say when');
        self::assertCount(0, $crawler->selectButton('Do it'));
    }

    public function testPeopleThemesAndSettingsToo(): void
    {
        $this->signIn('admin');
        $kim = $this->createUser('Kim Ono', [Role::EDITOR]);

        $crawler = $this->client->request('GET', '/admin/find?q=suspend+kim');
        self::assertSame(['Suspend', 'Kim Ono'], $crawler->filter('.intent-part')->each(static fn (Crawler $part): string => trim($part->text())));
        $this->client->submit($crawler->selectButton('Do it')->form());
        self::assertResponseRedirects('/admin/users/'.$kim->id, 303);
        self::assertFalse($this->service(UserRepository::class)->find($kim->id)?->isActive());
        $this->client->request('GET', '/admin/find?q=suspend+kim');
        self::assertSelectorTextContains('.intent-denied', 'is suspended already');

        $crawler = $this->client->request('GET', '/admin/find?q='.rawurlencode('set site title to Tournament News'));
        self::assertSame(['Set', 'Site title', 'Tournament News'], $crawler->filter('.intent-part')->each(static fn (Crawler $part): string => trim($part->text())), 'The value as it was typed.');
        $this->client->submit($crawler->selectButton('Do it')->form());
        self::assertSame('Tournament News', $this->settings()->get('site.title'));

        $crawler = $this->client->request('GET', '/admin/find?q='.rawurlencode('invite ana@example.org, ben@example.org as editors'));
        self::assertSame(['Invite', 'ana@example.org', 'ben@example.org', 'as Editor'], $crawler->filter('.intent-part')->each(static fn (Crawler $part): string => trim($part->text())));
    }

    public function testOnlyWhatThePersonMayDo(): void
    {
        $this->signIn('editor');
        $this->createUser('Kim Ono', [Role::EDITOR]);

        $crawler = $this->client->request('GET', '/admin/find?q=suspend+kim');
        self::assertCount(0, $crawler->filter('#found-people'), 'People only for those who manage them.');
        self::assertCount(0, $crawler->selectButton('Do it'), 'Suspending takes managing users.');
        self::assertCount(1, $crawler->filter('.intent-denied'));

        $this->client->request('POST', '/admin/find', ['q' => 'suspend kim', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
    }
}
