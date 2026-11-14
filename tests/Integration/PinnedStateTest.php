<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\RecordingAppRebuilder;
use Doctrine\DBAL\Connection;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\State\DatabaseExtensionStateStore;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedState;
use Quire\Extension\State\PinnedStateListener;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Internal\ActiveThemeValidator;
use Quire\Theme\Themes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A site that stops pinning keeps what it ran pinned (ARC-0054). The test environment pins extensions, so
 * the services of an unpinned site are set up here, over the same database.
 */
final class PinnedStateTest extends KernelTestCase
{
    private Connection $connection;

    private DatabaseExtensionStateStore $store;

    private Settings $settings;

    private RecordingAppRebuilder $rebuilder;

    private PinnedState $pinnedState;

    private Themes $themes;

    /** @var list<string> */
    private array $output = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM quire.extension');
        $this->connection->executeStatement('DELETE FROM quire.pinned_state');

        $installed = $container->get(InstalledExtensions::class);
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $this->store = new DatabaseExtensionStateStore($this->connection);
        $extensions = new ExtensionManager($installed, $this->store, $dispatcher);
        $this->settings = new Settings($container->get(SettingsSchema::class), $this->connection, $dispatcher, validators: [new ActiveThemeValidator($installed, $extensions, pinned: false)]);
        $this->themes = new Themes($installed, $extensions, $this->settings, configured: null, chain: [], problem: null, pinned: false);
        $this->rebuilder = new RecordingAppRebuilder();
        $this->pinnedState = $this->pinnedState($this->store, $this->rebuilder);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testRecordingKeepsTheLatestPinnedStateAndSaysWhetherItChanged(): void
    {
        self::assertTrue($this->pinnedState->record(['quire-fixture-recipes', 'quire-fixture-hello'], 'quire-fixture-child'));
        self::assertFalse($this->pinnedState->record(['quire-fixture-hello', 'quire-fixture-recipes'], 'quire-fixture-child'), 'Same set, other order.');
        self::assertTrue($this->pinnedState->record(['quire-fixture-hello'], null));

        $row = $this->connection->fetchAssociative('SELECT enabled, theme FROM quire.pinned_state');
        self::assertSame(['enabled' => '["quire-fixture-hello"]', 'theme' => null], $row);
        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM quire.pinned_state'));
    }

    public function testStoppingPinningCarriesOverTheExtensionsAndTheThemeThenForgetsThem(): void
    {
        // Left in the database from before the site was pinned: no longer what it runs.
        $this->store->enable(['quire-fixture-recipes']);
        $this->pinnedState->record(['quire-fixture-hello', 'quire-analytics'], 'quire-fixture-child');

        self::assertTrue($this->pinnedState->carryOver($this->collect(...)));

        self::assertSame(['quire-analytics', 'quire-fixture-hello', 'quire-privacy'], $this->store->enabledIds(), 'With what they require, as in the admin.');
        self::assertSame('quire-fixture-child', $this->settings->get(Themes::SETTING));
        self::assertSame([true], $this->rebuilder->calls, 'One rebuild, with migrations.');
        self::assertStringContainsString('Disabled: quire-fixture-recipes.', implode('', $this->output));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM quire.pinned_state'), 'Forgotten, so it happens once.');

        self::assertFalse($this->pinnedState->carryOver($this->collect(...)));
        self::assertSame([true], $this->rebuilder->calls);
    }

    public function testChangesAreAuditedLikeTheAdminsOwn(): void
    {
        $this->pinnedState->record(['quire-fixture-hello'], 'quire-fixture-child');
        $this->pinnedState->carryOver($this->collect(...));

        /** @var list<string> $actions */
        $actions = $this->connection->fetchFirstColumn("SELECT action FROM quire.audit_entry WHERE action IN ('extension.enabled', 'theme.changed') ORDER BY id");
        self::assertSame(['extension.enabled', 'theme.changed'], $actions);
    }

    public function testExtensionsNoLongerInstalledAreSkippedAndNamed(): void
    {
        $this->pinnedState->record(['quire-fixture-hello', 'acme-gone'], null);

        self::assertTrue($this->pinnedState->carryOver($this->collect(...)));

        self::assertSame(['quire-fixture-hello'], $this->store->enabledIds());
        self::assertNull($this->settings->get(Themes::SETTING), 'Pinned without a theme: Quire\'s built-in templates.');
        self::assertStringContainsString('Not installed any more, so not enabled: acme-gone.', implode('', $this->output));
    }

    public function testAThemeThatCantBeChosenLeavesTheSiteOnItsThemeAndSaysWhy(): void
    {
        $this->pinnedState->record([], 'quire-fixture-orphan');

        self::assertTrue($this->pinnedState->carryOver($this->collect(...)));

        self::assertSame(Themes::DEFAULT, $this->settings->get(Themes::SETTING));
        self::assertStringContainsString('The theme it ran pinned, quire-fixture-orphan, can\'t be chosen', implode('', $this->output));
    }

    public function testASiteThatNeverRecordedAnythingMayHaveLostItsPinnedState(): void
    {
        self::assertTrue($this->pinnedState->mayHaveLostPinnedState());

        $this->pinnedState->record(['quire-fixture-hello'], null);
        self::assertFalse($this->pinnedState->mayHaveLostPinnedState(), 'There\'s a record to carry over.');

        $this->pinnedState->carryOver($this->collect(...));
        self::assertFalse($this->pinnedState->mayHaveLostPinnedState(), 'The database has its extensions.');

        $this->store->disable(['quire-fixture-hello']);
        self::assertFalse($this->pinnedState->mayHaveLostPinnedState(), 'All disabled is a choice, not a loss.');
    }

    public function testWhatTheKeptExtensionsRequireStaysEnabled(): void
    {
        // Pinned without what it requires (it couldn't run so), while the database already has that.
        $this->store->enable(['quire-privacy']);
        $this->pinnedState->record(['quire-analytics'], null);

        self::assertTrue($this->pinnedState->carryOver($this->collect(...)));

        self::assertSame(['quire-analytics', 'quire-privacy'], $this->store->enabledIds());
    }

    public function testAMigrateAfterThePinWasRemovedCarriesItOverOnceAndForgetsItBeforeRebuilding(): void
    {
        $this->pinnedState->record(['quire-fixture-hello'], 'quire-fixture-child');
        $rebuilder = new class($this->connection) implements AppRebuilderInterface {
            /** @var list<mixed> Whether the record was there at each rebuild */
            public array $recordAtRebuild = [];

            public function __construct(private readonly Connection $connection)
            {
            }

            public function rebuild(bool $migrate, \Closure $output): void
            {
                $this->recordAtRebuild[] = $this->connection->fetchOne('SELECT 1 FROM quire.pinned_state');
            }
        };

        [$status] = $this->migrated($this->pinnedState($this->store, $rebuilder));

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['quire-fixture-hello'], $this->store->enabledIds());
        self::assertSame('quire-fixture-child', $this->settings->get(Themes::SETTING));
        self::assertSame([false], $rebuilder->recordAtRebuild, 'Rebuilt once, with the record already gone.');
    }

    public function testAMigrateWhoseCarryOverFailsChangesNothingAndFails(): void
    {
        $this->store->enable(['quire-fixture-recipes']);
        $this->pinnedState->record(['quire-fixture-hello'], 'quire-fixture-child');
        $failing = new readonly class($this->store) implements ExtensionStateStoreInterface {
            public function __construct(private ExtensionStateStoreInterface $store)
            {
            }

            public function enabledIds(): array
            {
                return $this->store->enabledIds();
            }

            public function readOnlyReason(): ?string
            {
                return null;
            }

            public function enable(array $ids): void
            {
                $this->store->enable($ids);
            }

            public function disable(array $ids): void
            {
                throw new ExtensionStateException('The database went away.');
            }
        };

        [$status, $output] = $this->migrated($this->pinnedState($failing, $this->rebuilder));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('What the site ran pinned couldn\'t be carried over: The database went away.', $output);
        self::assertSame(['quire-fixture-recipes'], $this->store->enabledIds(), 'quire-fixture-hello\'s enabling was rolled back.');
        self::assertSame(Themes::DEFAULT, $this->settings->get(Themes::SETTING));
        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM quire.pinned_state'), 'Kept, to try again.');
        self::assertSame([], $this->rebuilder->calls);
    }

    /**
     * The listener, as when quire:migrations:migrate finished on a site that isn't pinned.
     *
     * @return array{int, string}
     */
    private function migrated(PinnedState $pinnedState): array
    {
        $output = new BufferedOutput();
        $event = new ConsoleTerminateEvent(new Command(PinnedStateListener::COMMAND), new ArrayInput([]), $output, Command::SUCCESS);
        new PinnedStateListener($pinnedState, $this->store, $this->themes, pinned: false)($event);

        return [$event->getExitCode(), (string) preg_replace('/\s+/', ' ', $output->fetch())];
    }

    private function pinnedState(ExtensionStateStoreInterface $store, AppRebuilderInterface $rebuilder): PinnedState
    {
        $container = self::getContainer();
        $installed = $container->get(InstalledExtensions::class);
        $extensions = new ExtensionManager($installed, $store, $container->get(EventDispatcherInterface::class));
        $themes = new Themes($installed, $extensions, $this->settings, configured: null, chain: [], problem: null, pinned: false);

        return new PinnedState($this->connection, $extensions, $store, $installed, $themes, $rebuilder);
    }

    private function collect(string $line): void
    {
        $this->output[] = $line;
    }
}
