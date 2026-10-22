<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\RecordingAppRebuilder;
use Doctrine\DBAL\Connection;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Console\UseThemeCommand;
use Quire\Theme\Internal\ActiveThemeValidator;
use Quire\Theme\Themes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Changing the theme at runtime, as on a site whose extensions aren't pinned. (The test environment pins
 * them, so the services are set up here the way they are then.).
 */
final class UseThemeTest extends KernelTestCase
{
    private Connection $connection;

    private Settings $settings;

    private RecordingAppRebuilder $rebuilder;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $installed = $container->get(InstalledExtensions::class);
        $extensions = $container->get(ExtensionManager::class);
        $this->settings = new Settings($container->get(SettingsSchema::class), $this->connection, $container->get(EventDispatcherInterface::class), validators: [new ActiveThemeValidator($installed, $extensions, pinned: false)]);
        $this->rebuilder = new RecordingAppRebuilder();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testUsingAThemeStoresItAndRebuildsTheApplication(): void
    {
        [$status, $output] = $this->use('quire-fixture-child');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('The site uses "quire-fixture-child".', $output);
        self::assertSame('quire-fixture-child', $this->settings->get(Themes::SETTING));
        self::assertSame([true], $this->rebuilder->calls);

        [$status] = $this->use(null);

        self::assertSame(0, $status);
        self::assertNull($this->settings->get(Themes::SETTING));
    }

    public function testThemesThatCantBeUsedAreRefused(): void
    {
        [$status, $output] = $this->use('quire-fixture-orphan');

        self::assertSame(1, $status);
        self::assertStringContainsString('inherits from "quire-fixture-gone", which isn\'t installed', $output);
        self::assertNull($this->settings->get(Themes::SETTING));
        self::assertSame([], $this->rebuilder->calls);

        $this->expectException(InvalidSettingValueException::class);
        $this->expectExceptionMessageIsOrContains('No theme with ID "acme-missing" is installed. Installed: "quire-fixture-base", "quire-fixture-child", "quire-fixture-orphan".');
        $this->settings->set(Themes::SETTING, 'acme-missing');
    }

    public function testAFailedRebuildPutsThePreviousThemeBack(): void
    {
        $this->rebuilder = new RecordingAppRebuilder(failWith: 'The theme\'s extend.php failed.');

        [$status, $output] = $this->use('quire-fixture-base');

        self::assertSame(1, $status);
        self::assertStringContainsString('The theme\'s extend.php failed. The previous theme is back in use.', $output);
        self::assertNull($this->settings->get(Themes::SETTING));
        self::assertSame([true, false], $this->rebuilder->calls);
    }

    /**
     * @return array{int, string}
     */
    private function use(?string $id): array
    {
        $container = self::getContainer();
        $themes = new Themes($container->get(InstalledExtensions::class), $container->get(ExtensionManager::class), $this->settings, configured: null, chain: [], problem: null, pinned: false);
        $output = new BufferedOutput();
        $status = new UseThemeCommand($themes, $this->rebuilder)(new SymfonyStyle(new ArrayInput([]), $output), $id, none: null === $id);

        return [$status, (string) preg_replace('/\s+/', ' ', $output->fetch())];
    }
}
