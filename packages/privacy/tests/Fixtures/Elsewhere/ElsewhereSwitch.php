<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests\Fixtures\Elsewhere;

use Quire\Privacy\ServiceSwitchInterface;

/**
 * A switch of a package that didn't declare the service, e.g. a theme's, which would turn on another
 * package's service or give it values of its own.
 */
final class ElsewhereSwitch implements ServiceSwitchInterface
{
    public static function service(): string
    {
        return 'acme-stats';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function config(): array
    {
        return ['id' => 'THEIRS'];
    }
}
