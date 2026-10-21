<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class HelloController
{
    public function __construct(
        private Greeter $greeter,
        private Environment $twig,
    ) {
    }

    public function __invoke(string $name): Response
    {
        return new Response($this->twig->render('@hello/hello.html.twig', [
            'name' => $name,
            'message' => $this->greeter->message($name),
        ]));
    }
}
