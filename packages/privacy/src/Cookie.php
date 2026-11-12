<?php

declare(strict_types=1);

namespace Quire\Privacy;

/**
 * A cookie, or other storage on the visitor's device, that a service uses.
 *
 * @api
 */
final readonly class Cookie
{
    /**
     * @param string $name     Its name, e.g. "_ga"; "*" at the end for a family, e.g. "_ga_*"
     * @param string $lifetime How long it stays, for visitors, e.g. "2 years" or "The session"
     * @param string $purpose  What it's for, for visitors
     */
    public function __construct(
        public string $name,
        public string $lifetime,
        public string $purpose,
    ) {
        if (1 !== preg_match('/^[!#$%&\'+\-.^_`|~0-9A-Za-z]+\*?$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a cookie\'s name (a trailing "*" names a family, e.g. "_ga_*").', $name));
        }
    }
}
