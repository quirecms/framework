<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Psr\Cache\CacheItemPoolInterface;
use Quire\Admin\Controller\SetupController;
use Quire\Admin\Dashboard\Inbox\ReportOnlyInbox;
use Quire\Csp\Csp;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Testing\AdminTestCase;
use Quire\User\Mail\MailSetup;
use Quire\User\Mail\UserMailer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The site's setup in the admin, read-only: its address, e-mail, and security policy, with where each is
 * changed; and a test e-mail, only to the signed-in user's own confirmed address.
 */
final class SetupTest extends AdminTestCase
{
    public function testOnlyThoseWhoManageSettingsSeeIt(): void
    {
        $this->signIn('editor');
        $this->client->request('GET', '/admin/setup');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItShowsTheSetupAndWhereItsChanged(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/setup');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The site sends no e-mails', $crawler->filter('section[aria-labelledby=setup-mail]')->text(), 'Tests send with null://.');
        self::assertStringNotContainsString('null://', (string) $this->client->getResponse()->getContent(), 'Never the DSN.');
        self::assertStringContainsString('enforced', $crawler->filter('section[aria-labelledby=setup-security]')->text());
        self::assertStringContainsString('config/packages/quire.yaml', $crawler->filter('section[aria-labelledby=setup-security]')->text());
        self::assertCount(1, $crawler->filter('nav.section-switch a[href="/admin/setup"][aria-current=page]'));
    }

    public function testATestEmailGoesOnlyToTheUsersOwnConfirmedAddress(): void
    {
        $this->service(CacheItemPoolInterface::class)->clear();
        $admin = $this->signIn('admin');
        $this->withMailServer();
        $this->connection()->executeStatement('UPDATE quire.app_user SET email = ?, email_verified_at = now() WHERE id = ?', ['admin@site.example', $admin->id]);
        $token = $this->token();

        $this->client->request('POST', '/admin/setup/test-mail', ['_token' => $token, 'to' => 'someone@else.example']);

        self::assertResponseRedirects('/admin/setup');
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', 'admin@site.example');
        self::assertStringNotContainsString('someone@else.example', $message->toString());
        self::assertNotEmpty($this->audited('mail.tested', $admin->id));
    }

    public function testNotWithoutAMailServer(): void
    {
        $this->signIn('admin');
        $this->client->request('POST', '/admin/setup/test-mail', ['_token' => $this->token()]);

        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--error', 'The site sends no e-mails');
    }

    public function testNotWithoutAConfirmedAddress(): void
    {
        $admin = $this->signIn('admin');
        $this->withMailServer();
        $this->connection()->executeStatement('UPDATE quire.app_user SET email_verified_at = NULL WHERE id = ?', [$admin->id]);

        $this->client->request('POST', '/admin/setup/test-mail', ['_token' => $this->token()]);

        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--error', 'confirm your e-mail address first');
    }

    public function testFiveAnHour(): void
    {
        $this->service(CacheItemPoolInterface::class)->clear();
        $admin = $this->signIn('admin');
        $this->connection()->executeStatement('UPDATE quire.app_user SET email = ?, email_verified_at = now() WHERE id = ?', ['admin@site.example', $admin->id]);
        $token = $this->token();
        $this->withMailServer();

        for ($i = 0; $i <= SetupController::TEST_MAILS; ++$i) {
            $this->client->request('POST', '/admin/setup/test-mail', ['_token' => $token]);
        }

        self::assertEmailCount(0, message: 'The last one, above the limit.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--error', 'test e-mails in the last hour');
    }

    public function testHomeWarnsWhenTheSecurityPolicyOnlyReportsInProduction(): void
    {
        $urls = $this->service(UrlGeneratorInterface::class);
        $translator = $this->service(TranslatorInterface::class);
        $reporting = new Csp(new NoThirdPartySources(), reportOnly: true);

        self::assertCount(1, [...new ReportOnlyInbox($reporting, $urls, $translator, 'prod')->items()]);
        self::assertCount(0, [...new ReportOnlyInbox(new Csp(new NoThirdPartySources()), $urls, $translator, 'prod')->items()], 'Enforced: nothing to say.');
        self::assertCount(0, [...new ReportOnlyInbox($reporting, $urls, $translator, 'dev')->items()], 'Trying a policy out while developing is fine.');
    }

    private function token(): string
    {
        return $this->csrfToken('quire_admin_setup');
    }

    /**
     * The site as if it had a mail server: tests send through null://, which drops e-mails (and the page
     * says so), but the mailer still sees what's sent.
     */
    private function withMailServer(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(MailSetup::class, new MailSetup($this->service(UserMailer::class), ['smtp://smtp.example.com:587']));
    }
}
