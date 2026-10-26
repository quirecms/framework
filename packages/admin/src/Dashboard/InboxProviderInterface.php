<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard;

/**
 * Adds what waits for the current user to the home page's "Needs you", added with
 * {@see \Quire\Admin\Extend\Admin::inbox()}. It's a service, asked on every page (the menu shows how
 * many things wait), so it should take one or two queries.
 *
 * @api
 */
interface InboxProviderInterface
{
    /**
     * What waits for the current user, only what they may act on.
     *
     * @return iterable<InboxItem>
     */
    public function items(): iterable;
}
