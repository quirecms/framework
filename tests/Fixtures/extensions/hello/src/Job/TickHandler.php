<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Job;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TickHandler
{
    public static int $ticks = 0;

    public function __invoke(Tick $job): void
    {
        ++self::$ticks;
    }
}
