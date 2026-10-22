<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A listing page whose template queries content itself.
 */
final readonly class PostsController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return new Response($this->twig->render('@hello/posts.html.twig', [
            'page' => $request->query->getInt('page', 1),
        ]));
    }
}
