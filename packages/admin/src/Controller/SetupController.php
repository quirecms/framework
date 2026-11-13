<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Quire\Audit\AuditLog;
use Quire\Csp\Csp;
use Quire\Settings\Settings;
use Quire\User\Mail\MailSetup;
use Quire\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The site's setup that whoever runs it decides, shown to the owner: its address, whether it can send
 * e-mails (with a test e-mail to oneself), and its Content-Security-Policy, with every host it allows
 * and why. Read-only: these are changed where the site is deployed, and the page says where.
 *
 * @internal
 */
final readonly class SetupController
{
    public const string ROUTE = 'quire_admin_setup';
    public const string ABILITY = 'quire.settings.manage';

    private const string CSRF_TOKEN = 'quire_admin_setup';

    /** Test e-mails an hour, per user: never a way to send many */
    public const int TEST_MAILS = 5;

    private RateLimiterFactory $tests;

    public function __construct(
        private Security $security,
        private Environment $twig,
        private Csp $csp,
        private MailSetup $mail,
        private Settings $settings,
        private AuditLog $audit,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
        #[Autowire(param: 'router.request_context.base_url')]
        private string $siteUri = '',
        #[Autowire(param: 'kernel.environment')]
        private string $environment = 'prod',
        private ?LoggerInterface $logger = null,
    ) {
        $this->tests = new RateLimiterFactory(['id' => 'quire_admin_test_mail', 'policy' => 'sliding_window', 'limit' => self::TEST_MAILS, 'interval' => '1 hour'], new CacheStorage($cache));
    }

    public function __invoke(): Response
    {
        $this->assertAllowed();
        $user = $this->security->getUser();
        $sources = [];

        foreach ($this->csp->sources() as $source) {
            $sources[$source['directive']][] = $source;
        }

        ksort($sources);

        return new Response($this->twig->render('@quire_admin/site/setup.html.twig', [
            'address' => '' === $this->siteUri ? null : $this->siteUri,
            'mail' => $this->mail->configured(),
            'mail_from' => \is_string($from = $this->settings->get('quire.mail.from')) ? $from : '',
            'verified' => $user instanceof User && $user->hasVerifiedEmail() ? $user->email : null,
            'report_only' => $this->csp->reportOnly(),
            'report_uri' => $this->csp->reportUri(),
            'production' => 'prod' === $this->environment,
            'sources' => $sources,
            'privacy_url' => $this->privacyUrl(),
            'private_leaves_out' => array_values(array_unique(array_column(array_filter($this->csp->sources(), static fn (array $source): bool => 'privacy' === $source['origin']), 'source'))),
        ]));
    }

    public function testMail(Request $request): RedirectResponse
    {
        $this->assertAllowed();

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $user = $this->security->getUser();

        if (!$this->mail->configured()) {
            $session->getFlashBag()->add('error', $this->translator->trans('The site sends no e-mails: no mail server is set (MAILER_DSN). Invitations and password resets don\'t arrive.', [], 'quire-admin'));
        } elseif (!$user instanceof User || !$user->hasVerifiedEmail()) {
            $session->getFlashBag()->add('error', $this->translator->trans('A test e-mail goes only to your own confirmed address: confirm your e-mail address first.', [], 'quire-admin'));
        } elseif (!$this->tests->create($user->id)->consume()->isAccepted()) {
            $session->getFlashBag()->add('error', $this->translator->trans('You sent {count} test e-mails in the last hour: try again later.', ['count' => self::TEST_MAILS], 'quire-admin'));
        } else {
            try {
                $this->mail->sendTest($user);
                $this->audit->record('mail.tested', 'user', $user->id, $user->displayName);
                $session->getFlashBag()->add('success', $this->translator->trans('A test e-mail is on its way to {email}.', ['email' => (string) $user->email], 'quire-admin'));
            } catch (TransportExceptionInterface $e) {
                // Its message can name the mail server or its user: the log has it, the page doesn't.
                $this->logger?->error('The test e-mail couldn\'t be sent: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $this->audit->record('mail.tested', 'user', $user->id, $user->displayName, ['failed' => true]);
                $session->getFlashBag()->add('error', $this->translator->trans('The site couldn\'t send it. Whoever runs the site finds the reason in its log.', [], 'quire-admin'));
            }
        }

        return new RedirectResponse($this->urls->generate(self::ROUTE), Response::HTTP_SEE_OTHER);
    }

    /** quire/privacy's page, where each service's hosts are listed, when it's there */
    private function privacyUrl(): ?string
    {
        try {
            return $this->urls->generate('quire_privacy_admin');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Seeing the site\'s setup takes the right to manage its settings.', [], 'quire-admin'));
        }
    }
}
