<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * What a package says about its texts ("extra.quire.translations"), a format the translation hub reads too:
 *
 *     {"version": 1, "source": "en", "delivery": "hub", "domains": ["acme-shop"]}
 *
 * Every key but "version" may be left out: the texts are in English, bundled, in the domain of the
 * package's ID. A package whose texts come from the hub names its domains.
 *
 * @api
 */
final readonly class TranslationsDeclaration
{
    /** The version of this format Quire reads */
    public const int VERSION = 1;

    /** Symfony's own domains: no package declares them, and no language artifact reaches them */
    public const array SYMFONY_DOMAINS = ['messages', 'security', 'validators'];

    /** A domain, e.g. "acme-shop" */
    public const string DOMAIN_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param list<string> $domains The package's translation domains
     * @param string       $source  The language its texts are written in
     */
    public function __construct(
        public array $domains,
        public TranslationDelivery $delivery = TranslationDelivery::Bundled,
        public string $source = 'en',
        public int $version = self::VERSION,
    ) {
    }

    /**
     * Whether a package may have the domain: "quire" and "quire-…" are Quire's own packages'.
     */
    public static function mayHave(string $packageName, string $domain): bool
    {
        $quires = 'quire' === $domain || str_starts_with($domain, 'quire-');

        return !\in_array($domain, self::SYMFONY_DOMAINS, true) && (!$quires || str_starts_with($packageName, 'quire/'));
    }
}
