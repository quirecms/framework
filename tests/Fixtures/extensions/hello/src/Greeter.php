<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello;

final class Greeter
{
    public function message(string $name): string
    {
        return \sprintf('The fixture extension greets %s.', $name);
    }
}
