<?php

declare(strict_types=1);

namespace Quire\Admin\Site;

use Quire\Admin\Content\Form\CoreWidgets;
use Quire\Admin\Content\Form\FieldControl;
use Quire\Admin\Content\Form\InvalidInputException;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldType;
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
    /** Settings with pages of their own */
    private const array ELSEWHERE = [Themes::SETTING, Permissions::SETTING];

    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
        private CoreWidgets $widgets,
        private ContentChecker $checker,
        private TranslatorInterface $translator,
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
            if ($group !== $this->group($definition) || 'environment' === $this->settings->source($key)) {
                continue;
            }

            $sent = $submitted[$key] ?? null;

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
        $html = '<table class="table check-levels"><tbody>';

        foreach ($this->checker->checks() as $check) {
            $name = htmlspecialchars($control->name.'['.$check['id'].']');
            $id = htmlspecialchars($control->id.'-'.str_replace('.', '-', $check['id']));
            $current = \is_string($levels[$check['id']] ?? null) ? $levels[$check['id']] : '';
            $options = '';

            foreach (['' => $this->translator->trans('Default ({level})', ['level' => $this->translator->trans('check.'.$check['default']->value, [], 'quire-admin')], 'quire-admin'), 'error' => $this->translator->trans('check.error', [], 'quire-admin'), 'warning' => $this->translator->trans('check.warning', [], 'quire-admin'), 'off' => $this->translator->trans('Off', [], 'quire-admin')] as $level => $label) {
                $options .= \sprintf('<option value="%s"%s>%s</option>', $level, $level === $current ? ' selected' : '', htmlspecialchars($label));
            }

            $html .= \sprintf('<tr><th scope="row"><label for="%s">%s</label> <span class="meta">%s</span></th><td><select name="%s" id="%s">%s</select></td></tr>', $id, htmlspecialchars($this->translator->trans($check['label'], [], $check['owner'])), htmlspecialchars($check['id']), $name, $id, $options);
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
