<?php

declare(strict_types=1);

namespace Quire\Admin\Site;

use Quire\Admin\Content\Form\CoreWidgets;
use Quire\Admin\Content\Form\FieldControl;
use Quire\Admin\Content\Form\InvalidInputException;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldType;
use Quire\Extension\ExtensionType;
use Quire\Extension\InstalledExtensions;
use Quire\Permission\Permissions;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\SettingDefinition;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;
use Quire\Theme\Themes;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * The settings of the site as forms, a group at a time: each setting with the widget its type takes
 * (the editor's), its label and help from its declaration.
 *
 * Settings with their own pages (the theme, permissions) aren't here. Settings an environment variable
 * sets are shown, not changed; sensitive ones, e.g. API keys, are never shown, only replaced.
 *
 * @internal
 *
 * @phpstan-type Entry array{definition: SettingDefinition, label: string, help: ?string, html: Markup, source: string, environment: ?string, sensitive: bool, error: ?string, fieldset: bool}
 */
final readonly class SettingsForm
{
    /** Core's settings in the order they read best; others follow, by key */
    private const array READING_ORDER = [
        'site.title', 'site.description', 'site.locale', 'site.timezone', 'site.homepage', 'site.homepage_listing', 'site.homepage_page', 'site.homepage_route', 'site.share_image',
        'quire.content.changes_need_approval', 'quire.content.check_levels', 'quire.content.revisions_keep', 'quire.content.per_page',
        'quire.auth.password_login', 'quire.auth.registration', 'quire.auth.email_verification', 'quire.auth.password_reset', 'quire.mail.from', 'quire.audit.retention_days',
    ];

    /** Settings that only say something when another has a value, e.g. the homepage's page when it shows one */
    private const array ONLY_WHEN = ['site.homepage_listing' => ['site.homepage', 'listing'], 'site.homepage_page' => ['site.homepage', 'page'], 'site.homepage_route' => ['site.homepage', 'route']];

    /** Settings with pages of their own */
    private const array ELSEWHERE = [Themes::SETTING, Permissions::SETTING, Permissions::ROLES_SETTING];

    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
        private CoreWidgets $widgets,
        private ContentChecker $checker,
        private TranslatorInterface $translator,
        private InstalledExtensions $installed,
    ) {
    }

    /** Core's groups, in this order; extensions' come after, by label */
    private const array ORDER = ['site', 'content', 'permalinks', 'users', 'appearance', 'storage'];

    /**
     * The groups of settings: the group a setting declares, or the extension that declares it.
     *
     * @return array<string, string> Labels, by group
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->definitions() as $definition) {
            $group = $this->group($definition);
            $groups[$group] ??= $this->groupLabel($group);
        }

        uksort($groups, static function (string $a, string $b) use ($groups): int {
            $position = static fn (string $group): int => false === ($index = array_search($group, self::ORDER, true)) ? \count(self::ORDER) : $index;

            return [$position($a), $groups[$a]] <=> [$position($b), $groups[$b]];
        });

        return $groups;
    }

    /**
     * @param array<string, string> $errors By key
     * @param array<string, mixed>  $values What was entered, by key, to show again
     *
     * @return list<Entry>
     */
    public function entries(string $group, array $errors = [], array $values = []): array
    {
        $entries = [];

        foreach ($this->definitions() as $key => $definition) {
            if ($group !== $this->group($definition)) {
                continue;
            }

            $source = $this->settings->source($key);
            $value = \array_key_exists($key, $values) ? $values[$key] : $this->settings->get($key);
            $describedBy = array_filter([null !== $definition->help ? $this->id($key).'-help' : null, isset($errors[$key]) ? $this->id($key).'-error' : null]);
            $control = new FieldControl(
                name: 'settings['.$key.']',
                id: $this->id($key),
                invalid: isset($errors[$key]),
                describedBy: [] === $describedBy ? null : implode(' ', $describedBy),
            );

            $entries[] = [
                'definition' => $definition,
                'label' => $this->translator->trans($definition->label ?? $key, [], $definition->owner),
                'help' => null === $definition->help ? null : $this->translator->trans($definition->help, [], $definition->owner),
                'html' => match (true) {
                    ContentChecker::LEVELS_SETTING === $key => $this->checkLevels($value, $control),
                    $definition->sensitive => new Markup(\sprintf('<input type="password" %s value="" autocomplete="new-password">', $control->attributes()), 'UTF-8'),
                    default => $this->widgets->render($this->widget($definition), $this->field($definition), $value, $control),
                },
                'source' => $source,
                'environment' => 'environment' === $source ? $definition->environmentVariable() : null,
                'sensitive' => $definition->sensitive,
                'error' => $errors[$key] ?? null,
                // Several controls: a fieldset with a legend instead of a label.
                'fieldset' => ContentChecker::LEVELS_SETTING === $key,
            ];
        }

        return $entries;
    }

    /**
     * The settings as a document: each group a paragraph of sentences, each setting in its own (its phrase,
     * or its label), with its value as it reads and what it is by default.
     *
     * @return list<array{group: string, label: string, owner: ?string, sentences: list<array{entry: Entry, control: string, before: string, after: string, shown: string, changed: bool, default: string}>}>
     */
    public function document(): array
    {
        $document = [];

        foreach ($this->groups() as $group => $label) {
            $sentences = [];
            $owner = null;

            $entries = $this->entries($group);
            $position = static fn (SettingDefinition $definition): int|false => array_search($definition->key, self::READING_ORDER, true);
            usort($entries, static fn (array $a, array $b): int => [false === $position($a['definition']), $position($a['definition']), $a['definition']->key] <=> [false === $position($b['definition']), $position($b['definition']), $b['definition']->key]);

            foreach ($entries as $entry) {
                $definition = $entry['definition'];
                [$on, $when] = self::ONLY_WHEN[$definition->key] ?? [null, null];

                if (null !== $on && $this->settings->get($on) !== $when) {
                    continue;
                }

                $owner ??= \in_array($definition->owner, ['core', 'site'], true) ? null : $this->ownerLabel($definition->owner);
                $phrase = null === $definition->phrase
                    ? $this->translator->trans('{label}: {value}.', ['label' => $entry['label']], 'quire-admin')
                    : $this->translator->trans($definition->phrase, [], $definition->owner);
                [$before, $after] = array_pad(explode('{value}', $phrase, 2), 2, '');
                $value = $this->settings->get($definition->key);
                $sentences[] = [
                    'entry' => $entry,
                    'control' => $this->id($definition->key),
                    'before' => $before,
                    'after' => $after,
                    'shown' => $this->shown($definition, $value),
                    'changed' => 'database' === $entry['source'] && $value !== $definition->default,
                    'default' => $this->shown($definition, $definition->default),
                ];
            }

            $document[] = ['group' => $group, 'label' => $label, 'owner' => $owner, 'sentences' => $sentences];
        }

        return $document;
    }

    /**
     * "the theme Default", "the extension Recipes": who declares a group's settings.
     */
    private function ownerLabel(string $id): string
    {
        $manifest = $this->installed->extensionsAndThemes()[$id] ?? null;

        return null === $manifest ? $id : $this->translator->trans(ExtensionType::Theme === $manifest->type ? 'the theme {name}' : 'the extension {name}', ['name' => $manifest->title], 'quire-admin');
    }

    /**
     * How a value reads in a sentence: its label, the value itself, or what it is; short for a list.
     */
    public function shown(SettingDefinition $definition, mixed $value, bool $short = false): string
    {
        // Short, e.g. in a list of changes: "on" instead of "need a reviewer's approval".
        $label = $short && \is_bool($value) ? null : $definition->valueLabel($value);

        return match (true) {
            null !== $label => $this->translator->trans($label, [], $definition->owner),
            $definition->sensitive => $this->translator->trans(null === $value || '' === $value ? 'not set' : 'set · hidden', [], 'quire-admin'),
            ContentChecker::LEVELS_SETTING === $definition->key && \is_array($value) => $this->levelsShown($value),
            null === $value, '' === $value, [] === $value => $this->translator->trans('not set', [], 'quire-admin'),
            'site.locale' === $definition->key && \is_string($value) => '' !== (string) \Locale::getDisplayName($value, $this->translator->getLocale()) ? (string) \Locale::getDisplayName($value, $this->translator->getLocale()) : $value,
            \is_bool($value) => $this->translator->trans($value ? 'on' : 'off', [], 'quire-admin'),
            \is_array($value) => $this->listShown($value),
            // Longer text, as it's said: in quotes.
            SettingType::String === $definition->type && 'textarea' === $definition->widget && \is_string($value) => '“'.$value.'”',
            default => \is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * A list as words, e.g. "news, results"; anything else as JSON.
     *
     * @param array<mixed> $value
     */
    private function listShown(array $value): string
    {
        if (array_is_list($value)) {
            return implode(', ', array_map(strval(...), array_filter($value, \is_scalar(...))));
        }

        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $json ? '' : $json;
    }

    /**
     * The checks that aren't at their default level, e.g. "Images have a description: error".
     *
     * @param array<mixed> $levels
     */
    private function levelsShown(array $levels): string
    {
        $checks = array_column($this->checker->checks(), null, 'id');
        $shown = [];

        foreach ($levels as $id => $level) {
            if (\is_string($level)) {
                $check = $checks[$id] ?? null;
                $label = null === $check ? (string) $id : $this->translator->trans($check['label'], [], $check['owner']);
                $shown[] = $label.' — '.('off' === $level ? $this->translator->trans('Off', [], 'quire-admin') : $this->translator->trans('check.'.$level, [], 'quire-admin'));
            }
        }

        return [] === $shown ? $this->translator->trans('each at its default level', [], 'quire-admin') : implode('; ', $shown);
    }

    /**
     * Saves the group's settings that changed.
     *
     * @param array<mixed> $submitted By key, as the form sent them
     *
     * @return array{changed: list<string>, errors: array<string, string>, values: array<string, mixed>}
     */
    public function save(string $group, array $submitted): array
    {
        [$changed, $errors, $values] = [[], [], []];

        foreach ($this->definitions() as $key => $definition) {
            // Only what the form sent: a form can be one setting's, e.g. in the settings document.
            if ($group !== $this->group($definition) || 'environment' === $this->settings->source($key) || !\array_key_exists($key, $submitted)) {
                continue;
            }

            $sent = $submitted[$key];

            // A sensitive setting changes only when a new value is entered.
            if ($definition->sensitive && (null === $sent || '' === $sent)) {
                continue;
            }

            try {
                $value = ContentChecker::LEVELS_SETTING === $key ? $this->levels($sent) : $this->value($definition, $sent);
            } catch (InvalidInputException $e) {
                $errors[$key] = $e->getMessage();
                $values[$key] = $sent;

                continue;
            }

            if ($this->normalized($value) === $this->normalized($this->settings->get($key))) {
                continue;
            }

            try {
                $this->settings->set($key, $value);
                $changed[] = $key;
            } catch (InvalidSettingValueException $e) {
                $errors[$key] = $e->getMessage();
                $values[$key] = $value;
            }
        }

        return ['changed' => $changed, 'errors' => $errors, 'values' => $values];
    }

    /**
     * The value with object keys sorted: JSON settings come back with their keys in PostgreSQL's order.
     */
    private function normalized(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalized(...), $value);
    }

    public function id(string $key): string
    {
        return 'setting-'.str_replace('.', '-', $key);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function definitions(): array
    {
        return array_diff_key($this->schema->all(), array_flip(self::ELSEWHERE));
    }

    private function group(SettingDefinition $definition): string
    {
        return $definition->group ?? $definition->owner;
    }

    private function groupLabel(string $group): string
    {
        $key = 'settings.group.'.$group;
        $label = $this->translator->trans($key, [], 'quire-admin');

        return $label !== $key ? $label : ucfirst(str_replace(['-', '_'], ' ', $group));
    }

    /**
     * The field a setting is edited as, for the editor's widgets.
     */
    private function field(SettingDefinition $definition): FieldDefinition
    {
        $type = match ($definition->type) {
            SettingType::String => FieldType::String,
            SettingType::Bool => FieldType::Bool,
            SettingType::Int => FieldType::Int,
            SettingType::Float => FieldType::Float,
            SettingType::Enum => FieldType::Enum,
            SettingType::List => FieldType::List,
            SettingType::Json => FieldType::Json,
        };

        return new FieldDefinition($definition->key, $type, $definition->owner, choices: $definition->choices, widget: $definition->widget);
    }

    private function widget(SettingDefinition $definition): string
    {
        $widget = $this->field($definition)->widget();

        return $this->widgets->has($widget) ? $widget : 'text';
    }

    /**
     * @throws InvalidInputException
     */
    private function value(SettingDefinition $definition, mixed $sent): mixed
    {
        $value = $this->widgets->value($this->widget($definition), $this->field($definition), $sent);

        return match (true) {
            null !== $value || $definition->nullable => $value,
            // Empty text is a value when the setting can't be empty-as-null.
            SettingType::String === $definition->type => '',
            SettingType::List === $definition->type => [],
            default => $value,
        };
    }

    /**
     * A choice per check: its default, or a level.
     */
    private function checkLevels(mixed $value, FieldControl $control): Markup
    {
        $levels = \is_array($value) ? $value : [];
        $html = '<table class="q-table check-levels"><tbody>';

        foreach ($this->checker->checks() as $check) {
            $name = htmlspecialchars($control->name.'['.$check['id'].']');
            $id = htmlspecialchars($control->id.'-'.str_replace('.', '-', $check['id']));
            $current = \is_string($levels[$check['id']] ?? null) ? $levels[$check['id']] : '';
            $options = '';

            foreach (['' => $this->translator->trans('Default ({level})', ['level' => $this->translator->trans('check.'.$check['default']->value, [], 'quire-admin')], 'quire-admin'), 'error' => $this->translator->trans('check.error', [], 'quire-admin'), 'warning' => $this->translator->trans('check.warning', [], 'quire-admin'), 'off' => $this->translator->trans('Off', [], 'quire-admin')] as $level => $label) {
                $options .= \sprintf('<option value="%s"%s>%s</option>', $level, $level === $current ? ' selected' : '', htmlspecialchars($label));
            }

            $html .= \sprintf('<tr><th scope="row"><label for="%s">%s</label> <span class="q-meta">%s</span></th><td><select name="%s" id="%s">%s</select></td></tr>', $id, htmlspecialchars($this->translator->trans($check['label'], [], $check['owner'])), htmlspecialchars($check['id']), $name, $id, $options);
        }

        return new Markup($html.'</tbody></table>', 'UTF-8');
    }

    /**
     * @return array<string, string>
     */
    private function levels(mixed $sent): array
    {
        $levels = [];

        foreach (\is_array($sent) ? $sent : [] as $check => $level) {
            if (\is_string($level) && \in_array($level, ['error', 'warning', 'off'], true)) {
                $levels[(string) $check] = $level;
            }
        }

        ksort($levels);

        return $levels;
    }
}
