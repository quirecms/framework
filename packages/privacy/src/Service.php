<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Quire\Csp\Policy;

/**
 * A service of the site that visitors may be asked about: who runs it, what for, what it keeps on
 * their device, where their data goes. Declared with {@see Extend\Privacy::service()}, it is the one
 * source of the banner, the settings dialog, the privacy page's list and the hosts of the site's
 * Content-Security-Policy, so they can't disagree.
 *
 * @api
 */
final readonly class Service
{
    /**
     * @param string                      $id        Unique on the site, e.g. "google-analytics"
     * @param string                      $label     Its name for visitors, e.g. "Google Analytics"
     * @param string                      $vendor    Who runs it, e.g. "Google Ireland Limited"
     * @param string                      $purpose   What it does for the site, in one sentence
     * @param list<Cookie>                $cookies   What it keeps on the visitor's device
     * @param array<string, list<string>> $hosts     Other sites it loads from or sends to, by
     *                                               Content-Security-Policy directive, e.g.
     *                                               ['script-src' => ['https://www.googletagmanager.com']]
     * @param string|null                 $retention How long the vendor keeps the data, e.g. "2 months"
     * @param string|null                 $policyUrl The vendor's privacy policy
     * @param string|null                 $script    An asset of the site's ("<id>/…") that starts the
     *                                               service once it's allowed, as a JavaScript module
     * @param array<string, scalar>       $config    What the script needs, e.g. a measurement ID: the
     *                                               same for every visitor, never a secret
     * @param string                      $owner     The extension or theme that declared it
     */
    public function __construct(
        public string $id,
        public string $label,
        public Category $category,
        public string $vendor,
        public string $purpose,
        public array $cookies = [],
        public array $hosts = [],
        public ?string $retention = null,
        public ?string $policyUrl = null,
        public ?string $script = null,
        public array $config = [],
        public string $owner = '',
    ) {
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a service\'s ID: lowercase letters, digits and "-", e.g. "google-analytics".', $id));
        }

        foreach (['label' => $label, 'vendor' => $vendor, 'purpose' => $purpose] as $name => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('The service "%s" needs a %s: visitors are told it.', $id, $name));
            }
        }

        foreach ($hosts as $directive => $sources) {
            if (!Policy::isFetchDirective($directive)) {
                throw new \InvalidArgumentException(\sprintf('The service "%s" declares hosts for "%s": only fetch directives such as "script-src", "img-src" or "frame-src" take them.', $id, $directive));
            }

            foreach ($sources as $source) {
                if (!Policy::isHost($source)) {
                    throw new \InvalidArgumentException(\sprintf('The service "%s" declares "%s" for "%s": only hosts such as "https://cdn.example.com" or "https://*.example.com", never a keyword or a whole scheme.', $id, $source, $directive));
                }
            }
        }

        if (null !== $policyUrl && 1 !== preg_match('#^https://[^\s"<>]+$#', $policyUrl)) {
            throw new \InvalidArgumentException(\sprintf('The service "%s" has the privacy policy "%s": it must be an https:// address.', $id, $policyUrl));
        }

        if (null !== $script && 1 !== preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*/[A-Za-z0-9._/-]+\.m?js$#', $script)) {
            throw new \InvalidArgumentException(\sprintf('The service "%s" starts with "%s": it must be a JavaScript file among the assets of an extension or theme, e.g. "acme-video/start.js".', $id, $script));
        }

        if (!$category->needsConsent() && null !== $script) {
            throw new \InvalidArgumentException(\sprintf('The service "%s" is necessary and has a script: a necessary service runs without asking, so it loads like the rest of the site (Extend\Assets), not through consent.', $id));
        }
    }

    /**
     * Changes when what a visitor agrees to changes: its category, the other sites it reaches, or what
     * it keeps on their device. A "yes" holds for one revision; after a change the visitor is asked
     * again. Wording (label, purpose) isn't part of it.
     */
    public function revision(): string
    {
        return substr(hash('sha256', json_encode([$this->category->value, $this->hosts, array_map(static fn (Cookie $cookie): string => $cookie->name, $this->cookies)], \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
