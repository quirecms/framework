<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManager;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Quire\Theme\Themes;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/**
 * A site with a theme that inherits from another: the fixture themes in tests/Fixtures/themes.
 */
final class ThemesTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-fixture-hello'];

    #[\Override]
    protected static ?string $theme = 'quire-fixture-child';

    private ?string $siteOverride = null;

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    protected function tearDown(): void
    {
        if (null !== $this->siteOverride) {
            new Filesystem()->remove($this->siteOverride);
        }

        parent::tearDown();
    }

    public function testTheActiveThemeIsBuiltOnTheThemesItInheritsFrom(): void
    {
        $themes = $this->service(Themes::class);

        self::assertSame('quire-fixture-child', $themes->active()?->id);
        self::assertSame(['quire-fixture-child', 'quire-fixture-base'], $themes->chain());
        self::assertNull($themes->problem());
    }

    public function testAChildThemeOverridesOneTemplateAndKeepsTheRestOfItsParent(): void
    {
        $this->publish('Hello world', 'The *body*.');

        $this->client->request('GET', '/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.theme-base', 'The layout comes from the parent theme.');
        self::assertSelectorTextSame('article.base-single h1', 'Hello world', 'The child\'s template extends the parent\'s.');
        self::assertSelectorTextSame('article .child-note', 'From the child theme');
        self::assertSelectorTextSame('article em', 'body');
    }

    public function testExtensionsFillTheSlotsOfTheTheme(): void
    {
        $this->publish('Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorTextSame('article.base-single .fixture-after-body', 'Thanks for reading "Hello world"');
        self::assertSelectorTextSame('article.base-single .fixture-likes', 'Like "Hello world"');
    }

    public function testThemeSettingsIncludeTheParentsAndTheChildsWin(): void
    {
        $this->publish('Hello world');
        $this->client->request('GET', '/blog/hello-world');
        self::assertSelectorExists('body[data-accent="green"][data-dates="yes"]');

        $this->setSetting('theme.quire-fixture-base.show_dates', false);
        $this->setSetting('theme.quire-fixture-base.accent', 'red');
        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorExists('body[data-accent="green"][data-dates="no"]', 'The child\'s "accent" hides the parent\'s.');
        self::assertSame('red', $this->service(Environment::class)->createTemplate("{{ quire_setting('theme.quire-fixture-base.accent') }}")->render(), 'Theme settings are exposed.');
    }

    public function testAChildThemesAssetsReplaceItsParentsWithTheSamePath(): void
    {
        $this->publish('Hello world');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertCount(1, $crawler->filter('head link[href^="/assets/quire-fixture-child/style-"]'), '"theme/style.css" is the child\'s file.');
        self::assertMatchesRegularExpression('#^/assets/quire-fixture-base/logo-[\w-]+\.svg$#', (string) $crawler->filter('header img.logo')->attr('src'), 'The child has no logo, so it\'s the parent\'s.');
        self::assertCount(0, $crawler->filter('head link[href^="/assets/quire-fixture-base/style-"]'));
    }

    public function testTheExtendersOfTheThemeAndItsParentsRun(): void
    {
        $this->publish('Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorExists('body.base-extend-ran.child-extend-ran');
    }

    public function testAThemeOverridesAnExtensionsTemplate(): void
    {
        $this->client->request('GET', '/_fixture/hello/Ada');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.child-hello h1', 'Hello, Ada!', 'The override includes the original as "@!hello/…".');
    }

    public function testTheSitesOwnTemplatesComeFirst(): void
    {
        $this->publish('Site override');
        $this->siteOverride = TestInstallation::projectDir().'/templates/content/post/site-override.html.twig';
        new Filesystem()->dumpFile($this->siteOverride, <<<'TWIG'
            {% extends '@quire-fixture-child/content/single.html.twig' %}
            {% block body %}<p class="site-note">From the site</p>{{ parent() }}{% endblock %}
            TWIG);

        $this->client->request('GET', '/blog/site-override');

        self::assertSelectorTextSame('.site-note', 'From the site');
        self::assertSelectorTextSame('.child-note', 'From the child theme', 'The site\'s template extends the theme\'s.');
    }

    public function testListsTheThemes(): void
    {
        $output = $this->console('quire:theme:list');

        self::assertMatchesRegularExpression('/quire-fixture-base\s.*\savailable/', $output);
        self::assertMatchesRegularExpression('/quire-fixture-child\s.*quire-fixture-base\s.*\sactive/', $output);
        self::assertStringContainsString('can\'t be used: The theme "quire-fixture-orphan" inherits from "quire-fixture-gone", which isn\'t installed.', $output);
        self::assertStringContainsString('The site uses "quire-fixture-child", which inherits from "quire-fixture-base".', $output);
    }

    public function testThemesAreNotExtensions(): void
    {
        try {
            $this->service(ExtensionManager::class)->planEnable(['quire-fixture-base']);
            self::fail('Themes can\'t be enabled as extensions.');
        } catch (UnknownExtensionException $e) {
            self::assertSame('"quire-fixture-base" is a theme, not an extension. Make it the site\'s theme with "bin/console quire:theme:use quire-fixture-base".', $e->getMessage());
        }

        self::assertStringNotContainsString('quire-fixture-base', $this->console('quire:extension:list'));
    }

    public function testThePinnedThemeCantBeChangedAtRuntime(): void
    {
        self::assertStringContainsString('The theme is pinned in configuration', $this->console('quire:theme:use', ['id' => 'quire-fixture-base'], 1));
    }

    private function publish(string $title, string $body = ''): Content
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', $title, body: $body));

        return $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = [], int $expectedStatus = 0): string
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($input, ['interactive' => false]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertSame($expectedStatus, $status, $output);

        return $output;
    }
}
