<?php

declare(strict_types=1);

namespace Quire\Csp;

/**
 * The one place other sites' hosts enter the site's policy: those of the services the visitor may be
 * asked to consent to (analytics, fonts, video), as `quire/privacy` declares them.
 *
 * Only `quire/privacy` implements it (doc 20, condition C-C): there's no general "add a CSP source" for
 * extensions, so a host can't load unless a declared service, which the privacy page lists and the
 * banner asks about, needs it. The container refuses an implementation from anywhere else. Without
 * `quire/privacy`, the site's pages load nothing from other sites but what the site's own
 * configuration allows ("quire.csp.sources").
 *
 * The sources are the same for every visitor, so pages stay cacheable: what a visitor allowed is up to
 * `quire/privacy`'s loader, which adds a provider's script only after consent.
 *
 * @api
 */
interface ThirdPartySourcesInterface
{
    /** The only namespace whose classes may implement it */
    public const string NAMESPACE = 'Quire\\Privacy\\';

    /**
     * Directive => sources, e.g. ['script-src' => ['https://www.googletagmanager.com']].
     *
     * @return array<string, list<string>>
     */
    public function sources(): array;
}
