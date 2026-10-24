<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Quire\Install\Console\InstallCommand;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class UserCommandsTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCreatesAUserWithAGeneratedPasswordWithoutATerminal(): void
    {
        [$status, $output] = $this->console('quire:user:create', ['email' => 'ada.lovelace@example.com', '--admin' => true]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Created Ada Lovelace (username "ada-lovelace", admin).', $output);
        self::assertSame(1, preg_match('/The password is: (\S{20})/', $output, $match), $output);
        $this->assertPassword('ada-lovelace', $match[1]);
    }

    public function testAsksForThePasswordTwice(): void
    {
        [$status, $output] = $this->console('quire:user:create', ['email' => 'grace@example.com', '--name' => 'Grace Hopper', '--role' => ['editor']], ['correct horse', 'wrong horse', 'correct horse', 'correct horse']);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('The passwords don\'t match.', $output);
        self::assertStringNotContainsString('The password is', $output, 'A typed password isn\'t shown.');
        $this->assertPassword('grace', 'correct horse');
    }

    public function testChangesPasswordsRolesAndStatus(): void
    {
        $this->console('quire:user:create', ['email' => 'grace@example.com', '--no-password' => true]);

        [, $output] = $this->console('quire:user:password', ['user' => 'grace'], ['new password', 'new password']);
        self::assertStringContainsString('Changed the password of Grace.', $output);
        $this->assertPassword('grace', 'new password');

        [, $output] = $this->console('quire:user:role', ['user' => 'grace@example.com', '--add' => ['editor', 'admin']]);
        self::assertStringContainsString('Grace: admin, editor', $output);
        // Another admin, so Grace isn't the last.
        $this->console('quire:user:create', ['email' => 'ada@example.com', '--no-password' => true, '--admin' => true]);
        [, $output] = $this->console('quire:user:role', ['user' => 'grace', '--remove' => ['admin']]);
        self::assertStringContainsString('Grace: editor', $output);
        [$status, $output] = $this->console('quire:user:role', ['user' => 'grace', '--add' => ['wizard']]);
        self::assertSame(1, $status);
        self::assertStringContainsString('Unknown role "wizard"', $output);

        [, $output] = $this->console('quire:user:suspend', ['user' => 'grace']);
        self::assertStringContainsString('Suspended Grace.', $output);
        [, $output] = $this->console('quire:user:list');
        self::assertMatchesRegularExpression('/grace\s+Grace\s+grace@example.com\s+editor\s+suspended\s+never/', $output);

        [$status, $output] = $this->console('quire:user:activate', ['user' => 'nobody']);
        self::assertSame(1, $status);
        self::assertStringContainsString('No user with the ID, email or username "nobody".', $output);
    }

    public function testInvitesUsersToChooseTheirPassword(): void
    {
        [$status, $output] = $this->console('quire:user:create', ['email' => 'grace@example.com', '--invite' => true]);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Invited grace@example.com by email to choose a password.', $output);
        self::assertEmailCount(1);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findByLogin('grace')?->passwordHash);

        [, $output] = $this->console('quire:user:invite', ['user' => 'grace']);
        self::assertStringContainsString('Invited Grace (grace@example.com) to choose a password.', $output);

        $this->console('quire:user:create', ['email' => 'ada@example.com', '--no-password' => true]);
        $this->connection->executeStatement("UPDATE quire.app_user SET email = NULL WHERE username = 'ada'");
        [$status, $output] = $this->console('quire:user:invite', ['user' => 'ada']);
        self::assertSame(1, $status);
        self::assertStringContainsString('The user has no email to send the invitation to.', $output);
    }

    public function testManagesSiteRolesAndExplainsAccess(): void
    {
        [$status, $output] = $this->console('quire:permissions:create-role', ['name' => 'translator', 'label' => 'Translator', '--from' => 'editor']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Created the role translator ("Translator"), with the abilities of editor.', $output);
        [, $output] = $this->console('quire:permissions:create-role', ['name' => 'translator', 'label' => 'Übersetzer']);
        self::assertStringContainsString('Renamed the role translator to "Übersetzer".', $output);

        $this->console('quire:user:create', ['email' => 'ana@example.com', '--no-password' => true, '--role' => ['translator']]);
        [, $output] = $this->console('quire:permissions:explain', ['user' => 'ana', 'ability' => 'content.post.publish']);
        self::assertStringContainsString('Ana may content.post.publish. * Their role "translator" has it, because the site granted it.', $output);
        [, $output] = $this->console('quire:permissions:explain', ['user' => 'ana', 'ability' => 'quire.settings.manage']);
        self::assertStringContainsString('Ana may not quire.settings.manage. * None of their roles (translator) has it.', $output);

        [$status, $output] = $this->console('quire:permissions:delete-role', ['name' => 'editor']);
        self::assertSame(1, $status);
        self::assertStringContainsString('"editor" is declared by Quire', $output);
        [$status] = $this->console('quire:permissions:delete-role', ['name' => 'translator']);
        self::assertSame(1, $status, 'A user has it: not without asking.');
        [, $output] = $this->console('quire:permissions:delete-role', ['name' => 'translator', '--force' => true]);
        self::assertStringContainsString('Deleted the role translator.', $output);
        self::assertSame([], self::getContainer()->get(UserRepository::class)->findByLogin('ana')?->roles);
    }

    public function testKeepsAnActiveAdmin(): void
    {
        $this->connection->executeStatement("UPDATE quire.app_user SET status = 'suspended' WHERE 'admin' = ANY(roles)");
        $this->console('quire:user:create', ['email' => 'ada@example.com', '--no-password' => true, '--admin' => true]);

        [$status, $output] = $this->console('quire:user:suspend', ['user' => 'ada']);
        self::assertSame(1, $status);
        self::assertStringContainsString('You can\'t suspend the last active admin', $output);
        [$status, $output] = $this->console('quire:user:role', ['user' => 'ada', '--remove' => ['admin']]);
        self::assertSame(1, $status);
        self::assertStringContainsString('You can\'t take the admin role from the last active admin', $output);
    }

    public function testDeletesUsersWithTheirContentReassignedOrKept(): void
    {
        $writer = self::getContainer()->get(UserWriter::class);
        $ada = $writer->create(new CreateUser('Ada', 'ada@example.com'));
        $grace = $writer->create(new CreateUser('Grace', 'grace@example.com'));
        self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Notes', authorId: $ada->id));

        [$status, $output] = $this->console('quire:user:delete', ['user' => 'ada']);
        self::assertSame(1, $status);
        self::assertStringContainsString('Ada is the author of 1 content item(s).', $output);

        [$status, $output] = $this->console('quire:user:delete', ['user' => 'ada', '--reassign' => 'grace']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Grace is now the author of their 1 content item(s).', $output);

        [$status, $output] = $this->console('quire:user:delete', ['user' => 'grace', '--keep-content' => true]);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Their 1 content item(s) have no author now.', $output);
        self::assertNull(self::getContainer()->get(UserRepository::class)->find($grace->id));
    }

    public function testRefusesPasswordsThatAreTooShort(): void
    {
        [$status, $output] = $this->console('quire:user:create', ['email' => 'grace@example.com'], ['short', 'short', 'short']);

        self::assertSame(1, $status);
        self::assertStringContainsString('Use at least 8 characters.', $output);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findByEmail('grace@example.com'));
    }

    public function testTheInstallerSetsUpTheSiteAndTheFirstAdmin(): void
    {
        $migrations = [];
        $installer = $this->installer($migrations);

        $installer->setInputs(['Ada\'s notes', 'de', '', 'ada@example.com', 'Ada Lovelace', 'correct horse', 'correct horse']);
        $status = $installer->execute([]);

        self::assertSame(0, $status, $installer->getDisplay());
        self::assertStringContainsString('quire:migrations:migrate', implode(' ', $migrations[0]), 'The database is prepared first.');
        self::assertStringContainsString('Your email is needed', $this->flat($installer->getDisplay()), 'An empty email is asked again.');
        self::assertStringContainsString('Quire is installed. Ada Lovelace (ada@example.com) is the admin. Manage the site at /admin.', $this->flat($installer->getDisplay()));
        self::assertSame(['quire:extension:enable', 'quire-admin', '--no-interaction', '--env=test'], \array_slice($migrations[1], 2), 'The admin interface is enabled.');
        $settings = self::getContainer()->get(Settings::class);
        self::assertSame(['Ada\'s notes', 'de'], [$settings->get('site.title'), $settings->get('site.locale')]);
        $this->assertPassword('ada', 'correct horse');
        self::assertSame(['admin'], self::getContainer()->get(UserRepository::class)->findByEmail('ada@example.com')?->roles);

        $again = $this->installer($migrations);
        self::assertSame(0, $again->execute([], ['interactive' => false]));
        self::assertStringContainsString('Quire is already installed: ada@example.com is an admin.', $this->flat($again->getDisplay()));
    }

    public function testTheInstallerWorksWithoutATerminal(): void
    {
        $migrations = [];
        $installer = $this->installer($migrations);

        self::assertSame(1, $installer->execute([], ['interactive' => false]));
        self::assertStringContainsString('The first admin needs an email: pass --admin-email.', $this->flat($installer->getDisplay()));

        self::assertSame(0, $installer->execute(['--admin-email' => 'ada@example.com'], ['interactive' => false]));
        self::assertSame(1, preg_match('/The admin\'s password is: (\S{20})/', $installer->getDisplay(), $match));
        $this->assertPassword('ada', $match[1]);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $answers Typed answers; without them, the command runs without a terminal
     *
     * @return array{int, string}
     */
    private function console(string $command, array $input = [], array $answers = []): array
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find($command));
        $tester->setInputs($answers);
        $status = $tester->execute($input, ['interactive' => [] !== $answers]);

        return [$status, $this->flat($tester->getDisplay())];
    }

    public function testTheInstallerLeavesPinnedExtensionsAlone(): void
    {
        $runs = [];
        $installer = $this->installer($runs, new PinnedExtensionStateStore([]));

        self::assertSame(0, $installer->execute(['--admin-email' => 'ada@example.com'], ['interactive' => false]));
        self::assertCount(1, $runs, 'Only the migrations.');
        self::assertStringContainsString('The admin interface isn\'t enabled: Extensions are pinned in', $this->flat($installer->getDisplay()));
        self::assertStringNotContainsString('Manage the site at', $this->flat($installer->getDisplay()));
    }

    /**
     * @param list<list<string>> $runs
     */
    private function installer(array &$runs, ?ExtensionStateStoreInterface $state = null): CommandTester
    {
        $container = self::getContainer();
        // A site where the admin is installed but not enabled yet.
        $state ??= new class implements ExtensionStateStoreInterface {
            public function enabledIds(): array
            {
                return [];
            }

            public function readOnlyReason(): ?string
            {
                return null;
            }

            public function enable(array $ids): void
            {
            }

            public function disable(array $ids): void
            {
            }
        };
        $command = new InstallCommand(
            $container->get(UserRepository::class),
            new InstalledExtensions($container->getParameter('quire.composer.installed_json')),
            new ExtensionManager(new InstalledExtensions($container->getParameter('quire.composer.installed_json')), $state, new EventDispatcher()),
            $container->get(UserWriter::class),
            $container->get(Settings::class),
            '/app',
            'test',
            '/admin',
            static function (array $command) use (&$runs): int {
                $runs[] = $command;

                return 0;
            },
        );

        return new CommandTester(new Command(null, $command));
    }

    private function assertPassword(string $username, string $password): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findByUsername($username);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue(self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class)->verify((string) $user->passwordHash, $password));
    }

    private function flat(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
