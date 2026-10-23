<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\TestInstallation;
use Quire\Testing\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Milestone 3 ("theming") end to end: a child theme overrides one template of the default theme, and an
 * extension adds its part to content.after_body in both, without knowing either.
 */
final class ThemingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideThemes(): iterable
    {
        yield 'the default theme' => ['quire-theme-default'];
        yield 'a child of the default theme' => ['quire-fixture-default-child'];
    }

    #[DataProvider('provideThemes')]
    public function testAnExtensionAddsToEveryTheme(string $theme): void
    {
        [$post, $home] = $this->pages($theme);

        self::assertSame('Thanks for reading "Hello world"', $post->filter('article.entry .fixture-after-body')->text(), 'The fixture extension\'s fragment, after the body.');
        self::assertSame('Like "Hello world"', $post->filter('article.entry .fixture-likes')->text(), 'And its second fragment, by priority.');
        self::assertCount(1, $post->filter('.skip-link'), 'The default theme\'s layout.');
        self::assertCount(1, $home->filter('main .card'), 'The default theme\'s list.');
    }

    public function testTheChildThemeOverridesOneTemplate(): void
    {
        [$childPost, $childHome] = $this->pages('quire-fixture-default-child');
        [$defaultPost, $defaultHome] = $this->pages('quire-theme-default');

        self::assertSame('Read by the child theme', $childPost->filter('main .child-banner')->text());
        self::assertCount(0, $defaultPost->filter('.child-banner'));
        self::assertSame($defaultHome->filter('main')->html(), $childHome->filter('main')->html(), 'Everything else is the default theme\'s.');
    }

    #[DataProvider('provideThemes')]
    public function testTheThemePassesItsChecks(string $theme): void
    {
        $kernel = $this->kernel($theme);
        $tester = new CommandTester(new Application($kernel)->find('quire:theme:lint'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $kernel->shutdown();
    }

    /**
     * A post page and the homepage, on a site with the theme and the fixture extension.
     *
     * @return array{Crawler, Crawler}
     */
    private function pages(string $theme): array
    {
        $kernel = $this->kernel($theme);
        $client = new KernelBrowser($kernel);
        $client->disableReboot();
        $connection = $this->service($kernel, Connection::class);
        $connection->beginTransaction();

        try {
            $writer = $this->service($kernel, ContentWriter::class);
            $post = $writer->create(new CreateContent('post', 'Hello world', body: 'The body.'));
            $writer->transition($post->id, $post->version, Status::PUBLISH, new \DateTimeImmutable('2026-01-15 10:00:00'));

            $pages = [$client->request('GET', '/blog/hello-world'), $client->request('GET', '/')];
            self::assertSame(200, $client->getResponse()->getStatusCode());

            return $pages;
        } finally {
            $connection->rollBack();
            $kernel->shutdown();
        }
    }

    private function kernel(string $theme): TestKernel
    {
        $kernel = new TestKernel(['quire-fixture-hello'], TestInstallation::projectDir().'/tests/Fixtures/installed.json', theme: $theme);
        $kernel->boot();

        return $kernel;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(TestKernel $kernel, string $class): object
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (the test container, which TestKernel enables)
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $service = $container->get($class);
        \assert($service instanceof $class);

        return $service;
    }
}
