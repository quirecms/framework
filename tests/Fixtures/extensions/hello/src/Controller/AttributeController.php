<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AttributeController
{
    #[Route('/attribute', name: 'fixture_attribute', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('Routed by attribute.');
    }
}
