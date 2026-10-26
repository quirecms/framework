<?php

declare(strict_types=1);

namespace Quire\Admin\Tests\Fixtures;

use Quire\Admin\Dashboard\InboxProviderInterface;

final class NoticesInbox implements InboxProviderInterface
{
    public function items(): iterable
    {
        return [];
    }
}
