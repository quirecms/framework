<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Security;

use Doctrine\DBAL\Connection;
use Quire\User\CreateUser;
use Quire\User\Exception\IdentityLinkRefusedException;
use Quire\User\Role;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Signs in whoever the pretend provider ({@see \Quire\Fixtures\Hello\Controller\SsoController}) vouches
 * for, the way a single sign-on extension would: the account is linked to a new member on its first
 * sign-in, and the user goes back to the page that asked them to sign in.
 */
final class SsoAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    use TargetPathTrait;

    public const string PROVIDER = 'fixture';

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserWriter $writer,
        private readonly Connection $connection,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'fixture_sso_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $account = trim($request->request->getString('account'));

        if ('' === $account) {
            throw new CustomUserMessageAuthenticationException('Choose an account at Fixture.');
        }

        return new SelfValidatingPassport(new UserBadge($account, fn (string $account): User => $this->users->findByIdentity(self::PROVIDER, $account) ?? $this->link($account)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // A page that asked the visitor to sign in: the firewall kept its URL, which is this site's.
        $session = $request->getSession();
        $target = $this->getTargetPath($session, $firewallName);
        $this->removeTargetPath($session, $firewallName);

        return new RedirectResponse(self::onSite($request->request->getString('next')) ?? $target ?? '/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Shown by the sign-in page, like the errors of signing in with a password.
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse('/login');
    }

    public function isInteractive(): bool
    {
        return true;
    }

    /**
     * A new user for the account, in one transaction with its link, so a refused link leaves no user behind.
     */
    private function link(string $account): User
    {
        $claims = ['name' => ucfirst($account), 'email' => $account.'@fixture.example'];

        try {
            return $this->connection->transactional(function () use ($account, $claims): User {
                $user = $this->writer->create(new CreateUser($claims['name'], $claims['email'], roles: [Role::MEMBER]));
                $this->writer->linkIdentity($user->id, self::PROVIDER, $account, $claims);

                return $user;
            });
        } catch (IdentityLinkRefusedException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }
    }

    /**
     * A path on this site, never another site's address, so the button's link can't send people elsewhere.
     */
    private static function onSite(string $path): ?string
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\') ? $path : null;
    }
}
