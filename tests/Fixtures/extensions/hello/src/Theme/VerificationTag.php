<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Theme;

use Quire\Head\Head;
use Quire\Head\HeadContributorInterface;

/**
 * A head tag of the extension's own: a search engine's verification code.
 */
final readonly class VerificationTag implements HeadContributorInterface
{
    public function contribute(Head $head, array $context): void
    {
        $head->meta('google-site-verification', 'quire-fixture-hello');
    }
}
