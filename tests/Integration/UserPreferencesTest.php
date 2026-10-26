<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Permission\Access;
use Quire\User\CreateUser;
use Quire\User\Invitations;
use Quire\User\Preference\InvalidPreferenceException;
use Quire\User\Preference\UserPreferences;
use Quire\User\Role;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Each person's preferences, invitations not used yet, and what another user may do.
 */
final class UserPreferencesTest extends KernelTestCase
{
    private Connection $connection;

    private UserPreferences $preferences;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->preferences = self::getContainer()->get(UserPreferences::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testEachPersonsPreferencesOverTheDefaults(): void
    {
        [$ada, $kim] = [$this->user('Ada'), $this->user('Kim')];

        self::assertNull($this->preferences->get($ada, UserPreferences::LANGUAGE), 'The site\'s language, by default.');
        $this->preferences->set($ada, UserPreferences::LANGUAGE, 'de');
        self::assertSame('de', $this->preferences->get($ada, UserPreferences::LANGUAGE));
        self::assertNull($this->preferences->get($kim, UserPreferences::LANGUAGE), 'Only theirs.');
        self::assertSame([UserPreferences::LANGUAGE => 'de'], $this->preferences->changed($ada));
        self::assertArrayHasKey(UserPreferences::LANGUAGE, $this->preferences->all($kim));

        // Set to the default, it's forgotten: a new default reaches them too.
        $this->preferences->set($ada, UserPreferences::LANGUAGE, null);
        self::assertSame([], $this->preferences->changed($ada));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM quire.user_preference WHERE user_id = ?', [$ada->id]));

        $this->preferences->set($ada, UserPreferences::LANGUAGE, 'de');
        $this->preferences->restoreDefault($ada);
        self::assertNull($this->preferences->get($ada, UserPreferences::LANGUAGE));
    }

    public function testWhatIsntAPreference(): void
    {
        $ada = $this->user('Ada');

        foreach ([['acme.nothing', 'x', 'No preference "acme.nothing" is declared.'], [UserPreferences::LANGUAGE, 12, 'The value doesn\'t fit the preference "quire.language"']] as [$name, $value, $message]) {
            try {
                $this->preferences->set($ada, $name, $value);
                self::fail('Accepted '.$name);
            } catch (InvalidPreferenceException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        // A value stored before the preference changed reads as the default.
        $this->connection->insert('quire.user_preference', ['user_id' => $ada->id, 'name' => UserPreferences::LANGUAGE, 'value' => '42']);
        $this->preferences->reset();
        self::assertNull($this->preferences->get($ada, UserPreferences::LANGUAGE));
        self::assertSame([], $this->preferences->changed($ada));
    }

    public function testInvitationsNotUsedYet(): void
    {
        [$lin, $sam] = [$this->user('Lin'), $this->user('Sam')];
        $invitations = self::getContainer()->get(Invitations::class);

        self::assertSame([], $invitations->pending([$lin->id]));
        $invitations->invite($lin);
        $invitations->invite($sam);
        $this->connection->executeStatement("UPDATE quire.user_token SET created_at = now() - INTERVAL '9 days', expires_at = now() - INTERVAL '2 days' WHERE user_id = ?", [$sam->id]);

        $pending = $invitations->pending([$lin->id, $sam->id]);
        self::assertSame([false, true], [$pending[$lin->id]->isExpired(), $pending[$sam->id]->isExpired()]);
        self::assertEqualsWithDelta(time(), $pending[$lin->id]->sentAt->getTimestamp(), 60);
        self::assertSame([], $invitations->pending([]));
    }

    public function testWhatAnotherUserMayDo(): void
    {
        [$editor, $member] = [$this->user('Edna', [Role::EDITOR]), $this->user('Mo', [Role::MEMBER])];
        $access = self::getContainer()->get(Access::class);
        $writer = self::getContainer()->get(ContentWriter::class);
        $theirs = $writer->create(new CreateContent('post', 'Theirs', authorId: $editor->id));
        $others = $writer->create(new CreateContent('post', 'Someone else\'s', authorId: $member->id));

        self::assertTrue($access->for($editor)->allows('content.post.publish'));
        self::assertFalse($access->for($member)->allows('content.post.publish'));
        self::assertTrue($access->for($editor)->allows('content.post.edit_own', $theirs));
        self::assertFalse($access->for($editor)->allows('content.post.edit_own', $others), 'The rules for the item apply.');
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles = []): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser($name, strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com', roles: $roles));
    }
}
