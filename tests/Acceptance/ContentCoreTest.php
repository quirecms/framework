<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\RecordingAppRebuilder;
use App\Tests\Support\ScratchDatabases;
use Doctrine\DBAL\Connection;
use Quire\Content\ContentRepository;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Milestone 2 ("content core") end to end: content created with the CLI renders at configurable URLs;
 * the fixture extension adds a typed field, a shortcode, and its own table with a relation that's loaded
 * for a whole listing at once; and a backup restored into an empty database reproduces the site.
 */
final class ContentCoreTest extends WebTestCase
{
    use ScratchDatabases;

    public function testASiteBuiltFromTheCommandLineSurvivesABackupAndARestoreIntoAnEmptyDatabase(): void
    {
        $client = $this->clientForNewDatabase('site');
        $this->buildSite();

        $site = $this->snapshot($client);
        $this->assertSiteWorks($site, $client);

        $backup = sys_get_temp_dir().'/quire-acceptance-'.bin2hex(random_bytes(4)).'.dump';

        try {
            $this->console('quire:backup', ['file' => $backup]);

            $client = $this->clientForNewDatabase('restored', migrate: false);
            $rebuilds = $this->recordRebuilds();
            $this->console('quire:restore', ['file' => $backup]);

            self::assertSame([true], $rebuilds->calls, 'The application is rebuilt for the restored extensions, and pending migrations run.');
            self::assertEquals($site, $this->snapshot($client), 'The restored site is the same, page by page.');
            self::assertSame('happy', self::getContainer()->get(ContentRepository::class)->query('post')->where('slug', '=', 'hello-world')->first()?->fields['mood']);
        } finally {
            @unlink($backup);
        }
    }

    private function buildSite(): void
    {
        $this->console('quire:settings:set', ['key' => 'site.title', 'value' => 'Acceptance']);
        $this->console('quire:settings:set', ['key' => 'quire.permalinks.post', 'value' => '/{year}/{month}/{slug}']);

        $hello = $this->create(['type' => 'post', 'title' => 'Hello', '--body' => "Hi! [[greeting name=Ada]]\n\nThe *first* post.", '--fields' => '{"mood": "happy"}', '--publish' => true]);
        $this->create(['type' => 'post', 'title' => 'Second', '--body' => 'Another post.', '--publish' => true]);
        $this->create(['type' => 'post', 'title' => 'Draft', '--body' => 'Not public yet.']);
        $about = $this->create(['type' => 'page', 'title' => 'About', '--body' => 'About this site.', '--publish' => true]);
        $this->create(['type' => 'page', 'title' => 'Team', '--body' => 'Who we are.', '--parent' => $about, '--publish' => true]);

        // Renaming keeps the old URL working; a redirect from a previous site points at the item.
        $this->console('quire:content:update', ['id' => $hello, '--slug' => 'hello-world']);
        $this->console('quire:redirect:add', ['from' => '/2019/05/hello/', 'to' => $this->postUrl('hello-world')]);

        // The fixture extension's own table, related to content.
        $connection = self::getContainer()->get(Connection::class);

        foreach (['Ann', 'Bob'] as $name) {
            $connection->insert('quire.quire_fixture_hello_like', ['content_id' => $hello, 'name' => $name]);
        }
    }

    /**
     * @param array<string, array{status: int, location: ?string, body: string}> $snapshot
     */
    private function assertSiteWorks(array $snapshot, KernelBrowser $client): void
    {
        $post = $this->postUrl('hello-world');

        self::assertSame(200, $snapshot[$post]['status']);
        self::assertStringContainsString('<strong>Hello, Ada!</strong>', $snapshot[$post]['body'], 'The extension\'s shortcode renders.');
        self::assertStringContainsString('<em>first</em>', $snapshot[$post]['body']);
        self::assertSame([301, $post], [$snapshot[$this->postUrl('hello')]['status'], $snapshot[$this->postUrl('hello')]['location']]);
        self::assertSame([301, $post], [$snapshot['/2019/05/hello']['status'], $snapshot['/2019/05/hello']['location']]);
        self::assertSame(200, $snapshot['/about/team']['status']);
        self::assertSame(404, $snapshot['/'.date('Y/m').'/draft']['status']);
        self::assertStringContainsString('Second', $snapshot['/']['body']);
        self::assertStringContainsString('Hello: Ann, Bob', $snapshot['/_fixture/posts']['body'], 'The extension\'s relation is loaded.');

        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);
        $log->start();
        $client->request('GET', '/_fixture/posts');
        self::assertCount(3, $log->stop(), 'One query for the settings, one for the posts, one for all of their likes.');
    }

    /**
     * Every page of the site, as a visitor gets it. Error pages are left out: in the test environment they
     * show debug details that differ each time.
     *
     * @return array<string, array{status: int, location: ?string, body: string}>
     */
    private function snapshot(KernelBrowser $client): array
    {
        $urls = ['/', '/blog', $this->postUrl('hello-world'), $this->postUrl('second'), $this->postUrl('hello'), '/'.date('Y/m').'/draft', '/2019/05/hello', '/about', '/about/team', '/_fixture/posts', '/missing'];
        $snapshot = [];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            $response = $client->getResponse();
            $snapshot[$url] = ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'body' => $response->isSuccessful() ? (string) $response->getContent() : ''];
        }

        return $snapshot;
    }

    private function postUrl(string $slug): string
    {
        return '/'.date('Y/m').'/'.$slug;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return string The new item's ID
     */
    private function create(array $input): string
    {
        $output = $this->console('quire:content:create', $input);
        self::assertSame(1, preg_match('/with ID ([0-9a-f-]{36})/', $output, $match), $output);

        return $match[1];
    }

    private function recordRebuilds(): RecordingAppRebuilder
    {
        $rebuilder = new RecordingAppRebuilder();
        self::getContainer()->set(AppRebuilderInterface::class, $rebuilder);

        return $rebuilder;
    }
}
