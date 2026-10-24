<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A page of the admin, from another extension: a route in the group "admin".
 */
final readonly class GreetingsController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@hello/admin/greetings.html.twig'));
    }
}
