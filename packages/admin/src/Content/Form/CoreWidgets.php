<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Form;

use Quire\Admin\Content\ContentLists;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Markup;

/**
 * The widgets that core's field types name: text, textarea, markdown, number, checkbox, select, tags,
 * date, datetime, json and relation. Each is a block of content/widgets.html.twig, which a site can
 * override like any of the admin's templates.
 *
 * @internal
 */
final readonly class CoreWidgets
{
    public const array NAMES = ['text', 'textarea', 'markdown', 'number', 'checkbox', 'select', 'tags', 'date', 'datetime', 'json', 'relation'];

    /** The most items a relation's list offers without searching */
    public const int RELATION_CHOICES = 200;

    public function __construct(
        private Environment $twig,
        private Settings $settings,
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private ContentLists $lists,
        private TranslatorInterface $translator,
    ) {
    }

    public function has(string $widget): bool
    {
        return \in_array($widget, self::NAMES, true);
    }

    public function render(string $widget, FieldDefinition $field, mixed $value, FieldControl $control): Markup
    {
        $variables = ['field' => $field, 'control' => $control, 'value' => $this->display($widget, $value)];

        if ('relation' === $widget) {
            $variables['choices'] = $this->relationChoices($field, $value);
            $variables['multiple'] = $field->isMultipleRelation();
            $variables['types'] = $field->relationTypes();
        }

        return new Markup($this->twig->load('@quire_admin/content/widgets.html.twig')->renderBlock($widget, $variables), 'UTF-8');
    }

    /**
     * @throws InvalidInputException
     */
    public function value(string $widget, FieldDefinition $field, mixed $submitted): mixed
    {
        $text = \is_string($submitted) ? trim($submitted) : null;

        return match ($widget) {
            'checkbox' => \in_array($submitted, ['1', 'on', 'true'], true),
            'number' => match (true) {
                null === $text, '' === $text => null,
                !is_numeric($text) => throw new InvalidInputException($this->translator->trans('Enter a number.', [], 'quire-admin')),
                'int' === $field->type && 1 === preg_match('/^-?\d+$/', $text) => (int) $text,
                'int' === $field->type => throw new InvalidInputException($this->translator->trans('Enter a whole number.', [], 'quire-admin')),
                default => (float) $text,
            },
            'select' => '' === $text || null === $text ? null : array_find($field->choices, static fn (string|int $choice): bool => (string) $choice === $text) ?? $text,
            'tags' => null === $text || '' === $text ? null : array_values(array_unique(array_filter(array_map(trim(...), explode(',', $text)), static fn (string $tag): bool => '' !== $tag))),
            'datetime' => null === $text || '' === $text ? null : $this->dateTime($text),
            'json' => null === $text || '' === $text ? null : $this->json($text),
            'relation' => $this->relation($field, $submitted),
            // Text keeps its spaces: they can matter in Markdown.
            'textarea', 'markdown' => \is_string($submitted) && '' !== trim($submitted) ? str_replace("\r\n", "\n", $submitted) : null,
            default => '' === $text ? null : $text,
        };
    }

    /**
     * The value as the control shows it.
     */
    private function display(string $widget, mixed $value): mixed
    {
        return match (true) {
            'datetime' === $widget && \is_string($value) && false !== strtotime($value) => new \DateTimeImmutable($value)->setTimezone($this->timezone())->format('Y-m-d\TH:i'),
            'tags' === $widget && \is_array($value) => implode(', ', array_filter($value, \is_scalar(...))),
            'json' === $widget && null !== $value && !\is_string($value) => json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'relation' === $widget => array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...))),
            // Controls show text: anything else, e.g. a list in a text field, as JSON.
            null === $value, \is_scalar($value) => $value,
            default => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        };
    }

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->settings->getString('site.timezone'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private function dateTime(string $text): \DateTimeImmutable
    {
        foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $format) {
            $at = \DateTimeImmutable::createFromFormat($format, $text, $this->timezone());

            if (false !== $at) {
                return $at;
            }
        }

        throw new InvalidInputException($this->translator->trans('Enter a date and time.', [], 'quire-admin'));
    }

    private function json(string $text): mixed
    {
        try {
            return json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidInputException($this->translator->trans('This isn\'t valid JSON: {problem}', ['problem' => $e->getMessage()], 'quire-admin'), $e->getCode(), $e);
        }
    }

    /**
     * @return list<string>|string|null
     */
    private function relation(FieldDefinition $field, mixed $submitted): array|string|null
    {
        $ids = array_values(array_unique(array_filter(\is_array($submitted) ? $submitted : [$submitted], static fn (mixed $id): bool => \is_string($id) && '' !== $id)));

        if ($field->isMultipleRelation()) {
            return [] === $ids ? null : $ids;
        }

        return $ids[0] ?? null;
    }

    /**
     * What a relation offers: the items linked now, then the latest changed items of its types that the
     * user may see (their lists' items, or published ones), up to {@see RELATION_CHOICES}.
     *
     * @return list<array{id: string, label: string}>
     */
    private function relationChoices(FieldDefinition $field, mixed $value): array
    {
        $choices = [];
        $linked = array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...)));

        foreach ($linked as $id) {
            $item = $this->content->find($id);

            if ($item instanceof Content) {
                $choices[$item->id] = $this->label($item);
            }
        }

        $types = array_values(array_filter($field->relationTypes(), $this->types->has(...)));
        $perType = max(1, intdiv(self::RELATION_CHOICES, max(1, \count($types))));

        foreach ($types as $type) {
            $definition = $this->types->get($type);
            $query = $this->lists->canList($definition) ? $this->lists->query($definition) : $this->content->query($type);

            foreach ($query->orderBy('updated_at', 'desc')->limit($perType)->get() as $item) {
                $choices[$item->id] ??= $this->label($item);
            }
        }

        return array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], array_keys($choices), $choices);
    }

    private function label(Content $item): string
    {
        $type = $this->types->has($item->type) ? $this->types->get($item->type)->label : $item->type;
        $status = 'published' === $item->status ? '' : ', '.$this->lists->statusLabel($item->status);

        return \sprintf('%s (%s%s)', $item->title, $type, $status);
    }
}
