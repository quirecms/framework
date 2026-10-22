<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Controller;

use Quire\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shows who is signed in, for tests of signing in and out.
 */
final readonly class WhoAmIController
{
    public function __construct(
        private Security $security,
    ) {
    }

    #[Route('/whoami', name: 'fixture_whoami', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->security->getUser();

        return new Response($user instanceof User ? $user->username : 'anonymous');
    }
}
