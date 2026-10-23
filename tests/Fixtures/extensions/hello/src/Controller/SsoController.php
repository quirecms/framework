<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A pretend identity provider, for the "Continue with Fixture" button: its page asks which account to
 * continue with and posts it back to the site, where {@see \Quire\Fixtures\Hello\Security\SsoAuthenticator}
 * signs the user in.
 */
final readonly class SsoController
{
    #[Route('/sso', name: 'fixture_sso', methods: ['GET'])]
    public function provider(Request $request): Response
    {
        return new Response(\sprintf(
            '<!doctype html><title>Fixture</title><form method="post" action="/_fixture/sso/callback"><label>Account <input name="account"></label><input type="hidden" name="next" value="%s"><button>Continue</button></form>',
            htmlspecialchars($request->query->getString('next')),
        ));
    }

    #[Route('/sso/callback', name: 'fixture_sso_callback', methods: ['POST'])]
    public function callback(): never
    {
        throw new \LogicException('The fixture\'s authenticator handles the callback before this runs.');
    }
}
