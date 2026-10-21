<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class FixtureHeaderListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set('X-Quire-Fixture', 'hello');
    }
}
