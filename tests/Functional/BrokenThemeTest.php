<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Quire\Theme\Themes;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A site whose theme can't be used, e.g. because its parent was uninstalled: it keeps working with
 * Quire's built-in templates.
 */
final class BrokenThemeTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-fixture-orphan';

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testTheSiteFallsBackToTheBuiltInTemplates(): void
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', 'Still here'));
        $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));

        $this->client->request('GET', '/blog/still-here');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('article h1', 'Still here');
        self::assertSelectorNotExists('title:contains("Never used")');

        $themes = $this->service(Themes::class);
        self::assertNull($themes->active());
        self::assertSame('quire-fixture-orphan', $themes->selected());
        self::assertSame('The theme "quire-fixture-orphan" inherits from "quire-fixture-gone", which isn\'t installed. Install it with Composer.', $themes->problem());
    }

    public function testTheThemeListReportsTheProblem(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('quire:theme:list'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('The site should use the theme "quire-fixture-orphan", but it uses Quire\'s built-in templates instead', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
