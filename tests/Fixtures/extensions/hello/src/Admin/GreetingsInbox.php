<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Dashboard\InboxItem;
use Quire\Admin\Dashboard\InboxProviderInterface;

final class GreetingsInbox implements InboxProviderInterface
{
    public function items(): iterable
    {
        yield new InboxItem(
            key: 'quire-fixture-hello.greetings',
            text: '2 greetings wait for **moderation**',
            url: '/admin/greetings',
            action: 'Moderate',
            at: new \DateTimeImmutable('-1 hour'),
            tags: [['label' => 'from visitors', 'tone' => InboxItem::TONE_NEUTRAL]],
        );
    }
}
