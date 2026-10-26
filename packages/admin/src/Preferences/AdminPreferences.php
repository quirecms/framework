<?php

declare(strict_types=1);

namespace Quire\Admin\Preferences;

use Quire\User\Preference\UserPreferences;

/**
 * How each person likes the admin: light or dark, contrast, text size, density, movement, and its
 * language. They're people's preferences (see {@see UserPreferences}), so they follow them to every
 * device, and Quire UI reads them as attributes of <html>.
 *
 * @internal
 */
final readonly class AdminPreferences
{
    /** Preference => its choices, the default first */
    public const array CHOICES = [
        self::THEME => ['system', 'light', 'dark'],
        self::CONTRAST => ['standard', 'high'],
        self::TEXT => ['md', 'sm', 'lg', 'xl'],
        self::DENSITY => ['comfortable', 'compact'],
        self::MOTION => ['system', 'reduce'],
    ];

    /** The fields of the form, by preference (dots don't survive in posted names) */
    public const array FIELDS = ['theme' => self::THEME, 'contrast' => self::CONTRAST, 'text' => self::TEXT, 'density' => self::DENSITY, 'motion' => self::MOTION, 'language' => UserPreferences::LANGUAGE];

    public const string THEME = 'quire-admin.theme';

    public const string CONTRAST = 'quire-admin.contrast';

    public const string TEXT = 'quire-admin.text';

    public const string DENSITY = 'quire-admin.density';

    public const string MOTION = 'quire-admin.motion';

    /** Null until the person chooses: then it depends on what they may do (see {@see UnderTheHood}) */
    public const string HOOD = 'quire-admin.hood';

    public function __construct(
        private UserPreferences $preferences,
    ) {
    }

    public function get(string $name): string
    {
        $value = $this->preferences->mine($name);

        return \is_string($value) ? $value : self::CHOICES[$name][0];
    }

    /**
     * The attributes of <html> that Quire UI reads, for what isn't the default.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_filter([
            'data-theme' => 'system' === $this->get(self::THEME) ? null : $this->get(self::THEME),
            'data-contrast' => 'high' === $this->get(self::CONTRAST) ? 'high' : null,
            'data-text' => 'md' === $this->get(self::TEXT) ? null : $this->get(self::TEXT),
            'data-density' => 'compact' === $this->get(self::DENSITY) ? 'compact' : null,
            'data-motion' => 'reduce' === $this->get(self::MOTION) ? 'reduce' : null,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The languages the admin speaks, by code, each in its own language, e.g. ["de" => "Deutsch"].
     *
     * @return array<string, string>
     */
    public function languages(): array
    {
        $languages = [];

        $files = glob(\dirname(__DIR__, 2).'/translations/quire-admin+intl-icu.*.yaml');

        foreach (false === $files ? [] : $files as $file) {
            $code = substr(basename($file, '.yaml'), \strlen('quire-admin+intl-icu.'));
            $name = \Locale::getDisplayLanguage($code, $code);
            $languages[$code] = mb_convert_case(false === $name ? $code : $name, \MB_CASE_TITLE);
        }

        ksort($languages);

        return $languages;
    }

    /**
     * The language the person reads the admin in, when they chose one it speaks; null for the site's.
     */
    public function language(): ?string
    {
        $chosen = $this->preferences->mine(UserPreferences::LANGUAGE);

        return \is_string($chosen) && isset($this->languages()[$chosen]) ? $chosen : null;
    }

    /**
     * @param array<string, mixed> $chosen From a form, by field (see {@see FIELDS}); preferences left out stay as they are
     */
    public function save(string $userId, array $chosen): void
    {
        foreach (self::FIELDS as $field => $name) {
            if (!\array_key_exists($field, $chosen)) {
                continue;
            }

            $value = $chosen[$field];

            if (UserPreferences::LANGUAGE === $name) {
                $this->preferences->set($userId, $name, \is_string($value) && isset($this->languages()[$value]) ? $value : null);
            } elseif (\is_string($value) && \in_array($value, self::CHOICES[$name], true)) {
                $this->preferences->set($userId, $name, $value);
            }
        }
    }
}
