<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\ScratchDatabases;
use Doctrine\DBAL\Connection;
use Quire\User\Role;
use Quire\User\Security\FormThrottle;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Milestone 4 ("identity & permissions") end to end, on sites installed from the command line: drafts are
 * visible only to users who may preview them; an extension signs visitors in with its own button on the
 * sign-in page; and visitors register, confirm their email and reset their password by email.
 */
final class IdentityAndPermissionsTest extends WebTestCase
{
    use ScratchDatabases;

    private KernelBrowser $client;

    /** The password the installer generated for "ada" */
    private string $adminPassword = '';

    public function testDraftsAreVisibleOnlyToUsersWhoMayPreviewThem(): void
    {
        $this->install('drafts');
        $editor = $this->createUser('grace@example.com', 'editor');
        $member = $this->createUser('max@example.com', 'member');
        $this->console('quire:content:create', ['type' => 'post', 'title' => 'Season opener', '--body' => 'Welcome back.', '--author' => 'grace', '--publish' => true]);
        $this->console('quire:content:create', ['type' => 'post', 'title' => 'Draw for the final', '--body' => 'Group A: …', '--author' => 'grace']);

        $this->signOut();
        self::assertSame(200, $this->statusOf('/blog/season-opener'));
        self::assertSame(404, $this->statusOf('/blog/draw-for-the-final'), 'Visitors don\'t learn that the draft exists.');

        $this->signIn('max', $member);
        self::assertSame(404, $this->statusOf('/blog/draw-for-the-final'), 'Nor do members.');

        $this->signIn('grace', $editor);
        self::assertSame(200, $this->statusOf('/blog/draw-for-the-final'), 'Editors preview drafts…');
        self::assertSelectorTextContains('article .notice', 'Preview');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), '…which are never cached…');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex', '…or indexed.');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Season opener');
        self::assertSelectorTextNotContains('main', 'Draw for the final', 'Lists show published items only, even to editors.');

        $this->signIn('ada', $this->adminPassword);
        self::assertSame(200, $this->statusOf('/blog/draw-for-the-final'), 'Admins have every ability.');

        // Changed grants apply on the next request, without rebuilding anything.
        $this->console('quire:permissions:grant', ['role' => 'member', 'abilities' => ['content.post.view_drafts']]);
        $this->signIn('max', $member);
        self::assertSame(200, $this->statusOf('/blog/draw-for-the-final'));
        $this->console('quire:permissions:reset', ['abilities' => ['content.post.view_drafts']]);
        self::assertSame(404, $this->statusOf('/blog/draw-for-the-final'));

        // Members-only posts: visitors are asked to sign in, members read them.
        $this->console('quire:permissions:revoke', ['role' => 'guest', 'abilities' => ['content.post.view']]);
        $this->console('quire:permissions:grant', ['role' => 'member', 'abilities' => ['content.post.view']]);
        self::assertSame(200, $this->statusOf('/blog/season-opener'));
        $this->signOut();
        $this->client->request('GET', '/blog/season-opener');
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));

        // Their author leaves; their posts stay, with another author.
        $this->console('quire:user:delete', ['user' => 'grace', '--reassign' => 'ada']);
        $ada = $this->user('ada')->id;
        self::assertSame([$ada, $ada], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT author_id FROM quire.content ORDER BY title'));
        self::assertNull(self::getContainer()->get(UserRepository::class)->findByEmail('grace@example.com'));
    }

    public function testAnExtensionSignsVisitorsInWithItsOwnButton(): void
    {
        $this->install('sso');
        $this->console('quire:permissions:revoke', ['role' => 'guest', 'abilities' => ['content.post.view']]);
        $this->console('quire:permissions:grant', ['role' => 'member', 'abilities' => ['content.post.view']]);
        $this->console('quire:content:create', ['type' => 'post', 'title' => 'Members news', '--body' => 'For members only.', '--publish' => true]);
        $this->signOut();

        // A visitor who follows a link to a members-only post signs in with the fixture extension, and is back.
        $this->client->request('GET', '/blog/members-news');
        $this->client->followRedirect();
        $this->client->clickLink('Continue with Fixture');
        $this->client->submitForm('Continue', ['account' => 'olga']);
        self::assertResponseRedirects('http://localhost/blog/members-news');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Members news');

        $olga = self::getContainer()->get(UserRepository::class)->findByIdentity('fixture', 'olga');
        self::assertSame([Role::MEMBER], $olga?->roles, 'A new member, linked to the account.');

        // A site where everyone signs in with the extension.
        $this->console('quire:settings:set', ['key' => 'quire.auth.password_login', 'value' => 'false']);
        $this->signOut();
        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('input[name="_password"]');
        $this->client->clickLink('Continue with Fixture');
        $this->client->submitForm('Continue', ['account' => 'olga']);
        self::assertSame('olga', $this->whoami(), 'The same user signs in again.');
    }

    public function testVisitorsRegisterConfirmTheirEmailAndResetTheirPassword(): void
    {
        $this->install('accounts');

        foreach (['quire.auth.registration', 'quire.auth.email_verification', 'quire.auth.password_reset'] as $setting) {
            $this->console('quire:settings:set', ['key' => $setting, 'value' => 'true']);
        }

        $this->signOut();
        $this->client->request('GET', '/login');
        $this->client->clickLink('Create an account');
        $this->client->submitForm('Create account', ['name' => 'Lin Chen', 'email' => 'lin@example.com', 'password' => 'correct horse']);
        self::assertSelectorTextContains('h1', 'Check your inbox');

        $this->client->request('GET', $this->link($this->email('Confirm your email address')));
        self::assertResponseRedirects('/login');
        $this->signIn('lin@example.com', 'correct horse');
        self::assertSame('lin', $this->whoami());
        self::assertSame([Role::MEMBER], $this->user('lin')->roles);

        $this->signOut();
        $this->client->request('GET', '/login');
        $this->client->clickLink('Forgot your password?');
        $this->client->submitForm('Send the link', ['email' => 'lin@example.com']);
        $this->client->request('GET', $this->link($this->email('Reset your password')));
        $this->client->followRedirect();
        $this->client->submitForm('Change password', ['password' => 'a new horse']);
        self::assertResponseRedirects('/login');

        $this->signIn('lin@example.com', 'a new horse');
        self::assertSame('lin', $this->whoami());
    }

    /**
     * A new site in an empty database, with the admin "ada" that the installer creates.
     */
    private function install(string $database): void
    {
        $this->client = $this->clientForNewDatabase($database, migrate: false);
        self::getContainer()->get(FormThrottle::CACHE_POOL)->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $output = $this->console('quire:install', ['--admin-email' => 'ada@example.com', '--title' => 'Cup news']);
        self::assertSame(1, preg_match('/The admin\'s password is: (\S{20})/', $output, $match), $output);
        $this->adminPassword = $match[1];
    }

    /**
     * @return string The generated password
     */
    private function createUser(string $email, string $role): string
    {
        $output = $this->console('quire:user:create', ['email' => $email, '--role' => [$role]]);
        self::assertSame(1, preg_match('/The password is: (\S{20})/', $output, $match), $output);

        return $match[1];
    }

    private function signIn(string $login, string $password): void
    {
        $this->signOut();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $login, '_password' => $password]);
        self::assertResponseRedirects();
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function statusOf(string $url): int
    {
        $this->client->request('GET', $url);

        return $this->client->getResponse()->getStatusCode();
    }

    private function whoami(): string
    {
        $this->client->request('GET', '/_fixture/whoami');

        return (string) $this->client->getResponse()->getContent();
    }

    private function email(string $subject): Email
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($subject, $message->getSubject());

        return $message;
    }

    private function link(Email $email): string
    {
        self::assertSame(1, preg_match('#https?://\S+/(?:verify-email|reset-password)/\S+#', (string) $email->getTextBody(), $match), (string) $email->getTextBody());

        return $match[0];
    }

    private function user(string $username): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findByUsername($username);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
