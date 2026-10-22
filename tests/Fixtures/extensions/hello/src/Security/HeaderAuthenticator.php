<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Security;

use Quire\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Signs in the user named in the "X-Fixture-User" header, like a trusted proxy would.
 */
final class HeaderAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('X-Fixture-User');
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge(
            (string) $request->headers->get('X-Fixture-User'),
            fn (string $username) => $this->users->findByUsername($username) ?? throw new UserNotFoundException(),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response('Unknown fixture user.', Response::HTTP_UNAUTHORIZED);
    }
}
