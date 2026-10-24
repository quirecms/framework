<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Form;

use Quire\Admin\Extend\Admin;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Type\ContentTypeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Twig\Markup;

/**
 * The editor's form of a content type: the item's own properties, then its fields, each with the
 * widget its type names (core's, or one an extension adds with {@see Admin::fieldWidget()}).
 *
 * Values and errors are keyed like the writer's violations: "title", "slug", "fields.<name>", …
 *
 * @internal
 *
 * @phpstan-type Values array{title: string, slug: string, body: string, excerpt: ?string, parent_id: ?string, position: ?int, author_id?: ?string, fields: array<string, mixed>}
 */
final readonly class ContentForm
{
    /**
     * @param array<string, array{class: string, owner: string}> $widgets  Extensions' widgets, by name
     * @param ServiceLocator<FieldWidgetInterface>               $services
     */
    public function __construct(
        private CoreWidgets $core,
        #[Autowire(param: Admin::FIELD_WIDGETS_PARAMETER)]
        private array $widgets,
        #[AutowireLocator(Admin::FIELD_WIDGET_TAG)]
        private ServiceLocator $services,
    ) {
    }

    /**
     * The type's fields in the order of the form: by their order, then as declared, grouped by their
     * group (fields without one first).
     *
     * @return array<string, list<FieldDefinition>> By group; "" for fields without one
     */
    public function groups(ContentTypeDefinition $type): array
    {
        $fields = array_values($type->fields);
        $position = array_flip(array_keys($type->fields));
        usort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => [$a->order, $position[$a->name]] <=> [$b->order, $position[$b->name]]);
        $groups = ['' => []];

        foreach ($fields as $field) {
            $groups[$field->group ?? ''][] = $field;
        }

        return array_filter($groups);
    }

    /**
     * @return Values
     */
    public function values(?Content $item): array
    {
        return [
            'title' => $item->title ?? '',
            'slug' => $item->slug ?? '',
            'body' => $item->body ?? '',
            'excerpt' => $item?->excerpt,
            'parent_id' => $item?->parentId,
            'position' => $item?->position,
            'author_id' => $item?->authorId,
            'fields' => $item->fields ?? [],
        ];
    }

    /**
     * The item as a pending revision would change it: its text and fields, with its own address and place.
     *
     * @return Values
     */
    public function pendingValues(Content $item, PendingRevision $pending): array
    {
        return [
            ...$this->values($item),
            'title' => $pending->proposed->title,
            'body' => $pending->proposed->body,
            'excerpt' => $pending->proposed->excerpt,
            'fields' => $pending->proposed->fields,
        ];
    }

    /**
     * The changes the form makes to a pending revision; unchanged values are left out. Its address and
     * place aren't part of it: they change the item itself.
     *
     * @param int    $expectedVersion The pending revision's version the editor opened
     * @param Values $values
     */
    public function pendingUpdate(ContentTypeDefinition $type, PendingRevision $pending, int $expectedVersion, array $values): UpdatePendingRevision
    {
        $command = new UpdatePendingRevision($pending->id, $expectedVersion);
        $proposed = $pending->proposed;

        if ($values['title'] !== $proposed->title) {
            $command = $command->title($values['title']);
        }

        if ($values['body'] !== $proposed->body) {
            $command = $command->body($values['body']);
        }

        if ($type->has('excerpt') && $values['excerpt'] !== $proposed->excerpt) {
            $command = $command->excerpt($values['excerpt']);
        }

        foreach ($values['fields'] as $name => $value) {
            $stored = isset($type->fields[$name]) && null !== $value ? $type->fields[$name]->normalize($value) : $value;

            if ($stored !== ($proposed->fields[$name] ?? null)) {
                $command = $command->field($name, $value);
            }
        }

        return $command;
    }

    /**
     * The item as the form would make it, without saving it: for content checks while editing.
     *
     * @param Values      $values
     * @param string|null $authorId For a new item: its author
     */
    public function preview(ContentTypeDefinition $type, ?Content $item, array $values, ?string $authorId = null): Content
    {
        $fields = $item->fields ?? [];

        foreach ($values['fields'] as $name => $value) {
            if (null === $value) {
                unset($fields[$name]);
            } else {
                $fields[$name] = isset($type->fields[$name]) ? $type->fields[$name]->normalize($value) : $value;
            }
        }

        $now = new \DateTimeImmutable();

        return new Content(
            id: $item->id ?? '00000000-0000-0000-0000-000000000000',
            type: $type->id,
            status: $item->status ?? 'draft',
            locale: $item->locale ?? 'en',
            translationGroupId: $item?->translationGroupId,
            title: $values['title'],
            slug: '' === $values['slug'] ? ($item->slug ?? '') : $values['slug'],
            path: $item->path ?? '',
            parentId: $values['parent_id'],
            position: $values['position'] ?? $item->position ?? 0,
            excerpt: $values['excerpt'],
            body: $values['body'],
            bodyFormat: $item->bodyFormat ?? 'markdown',
            fields: $fields,
            authorId: \array_key_exists('author_id', $values) ? $values['author_id'] : ($item->authorId ?? $authorId),
            version: $item->version ?? 1,
            publishedAt: $item?->publishedAt,
            createdAt: $item->createdAt ?? $now,
            updatedAt: $now,
            deletedAt: $item?->deletedAt,
        );
    }

    public function control(FieldDefinition $field, ?string $error, string $widget): FieldControl
    {
        $id = 'field-'.$field->name;
        $described = array_filter([null !== $field->help ? $id.'-help' : null, null !== $error ? $id.'-error' : null]);

        return new FieldControl(
            name: 'fields['.$field->name.']',
            id: $id,
            // A required checkbox would have to be ticked; a required yes/no only needs an answer, which it has.
            required: $field->required && 'checkbox' !== $widget,
            invalid: null !== $error,
            describedBy: [] === $described ? null : implode(' ', $described),
        );
    }

    /**
     * The widget the field is edited with: its own, or "text" (or "json" for values that aren't text)
     * when nothing provides it.
     */
    public function widget(FieldDefinition $field): string
    {
        $widget = $field->widget();

        return match (true) {
            $this->core->has($widget), isset($this->widgets[$widget]) => $widget,
            FieldStorage::TextList === $field->storage() => 'tags',
            FieldStorage::Unindexed === $field->storage() => 'json',
            default => 'text',
        };
    }

    public function render(FieldDefinition $field, mixed $value, ?string $error): Markup
    {
        $widget = $this->widget($field);
        $control = $this->control($field, $error, $widget);

        if ($this->core->has($widget)) {
            return $this->core->render($widget, $field, $value, $control);
        }

        $html = $this->extensionWidget($widget)->render($field, $value, $control);

        return $html instanceof Markup ? $html : new Markup(htmlspecialchars((string) $html), 'UTF-8');
    }

    /**
     * What the form sent, as values to save, with what couldn't be read as errors.
     *
     * @return array{values: Values, errors: array<string, string>}
     */
    public function read(ContentTypeDefinition $type, Request $request): array
    {
        $input = $request->request;
        $sent = $input->all('fields');
        $fields = [];
        $errors = [];

        foreach ($type->fields as $name => $field) {
            $widget = $this->widget($field);

            try {
                $fields[$name] = $this->core->has($widget)
                    ? $this->core->value($widget, $field, $sent[$name] ?? null)
                    : $this->extensionWidget($widget)->value($field, $sent[$name] ?? null);
            } catch (InvalidInputException $e) {
                $errors['fields.'.$name] = $e->getMessage();
                $fields[$name] = $sent[$name] ?? null;
            }
        }

        $position = trim($input->getString('position'));
        // Only those who may edit anyone's items choose the author; otherwise it stays as it is.
        $author = $input->has('author_id') ? ['author_id' => '' === $input->getString('author_id') ? null : $input->getString('author_id')] : [];

        return [
            'values' => [
                'title' => trim($input->getString('title')),
                'slug' => trim($input->getString('slug')),
                'body' => str_replace("\r\n", "\n", $input->getString('body')),
                'excerpt' => '' === trim($input->getString('excerpt')) ? null : trim($input->getString('excerpt')),
                'parent_id' => '' === $input->getString('parent_id') ? null : $input->getString('parent_id'),
                'position' => 1 === preg_match('/^\d+$/', $position) ? (int) $position : null,
                ...$author,
                'fields' => $fields,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param Values $values
     */
    public function create(ContentTypeDefinition $type, array $values, ?string $actorId): CreateContent
    {
        return new CreateContent(
            $type->id,
            $values['title'],
            body: $values['body'],
            slug: '' === $values['slug'] ? null : $values['slug'],
            excerpt: $type->has('excerpt') ? $values['excerpt'] : null,
            fields: array_filter($values['fields'], static fn (mixed $value): bool => null !== $value),
            parentId: $type->has('hierarchical') ? $values['parent_id'] : null,
            position: $type->has('hierarchical') ? $values['position'] : null,
            authorId: $type->has('author') ? ($values['author_id'] ?? $actorId) : null,
            actorId: $actorId,
        );
    }

    /**
     * The changes the form makes to the item; unchanged values are left out.
     *
     * @param int    $expectedVersion The version the editor opened
     * @param Values $values
     */
    public function update(ContentTypeDefinition $type, Content $item, int $expectedVersion, array $values, ?string $actorId): UpdateContent
    {
        $command = new UpdateContent($item->id, $expectedVersion, actorId: $actorId);

        if ($values['title'] !== $item->title) {
            $command = $command->title($values['title']);
        }

        if ('' !== $values['slug'] && $values['slug'] !== $item->slug) {
            $command = $command->slug($values['slug']);
        }

        if ($values['body'] !== $item->body) {
            $command = $command->body($values['body']);
        }

        if ($type->has('excerpt') && $values['excerpt'] !== $item->excerpt) {
            $command = $command->excerpt($values['excerpt']);
        }

        if ($type->has('hierarchical')) {
            if ($values['parent_id'] !== $item->parentId) {
                $command = $command->parent($values['parent_id']);
            }

            if (null !== $values['position'] && $values['position'] !== $item->position) {
                $command = $command->position($values['position']);
            }
        }

        if ($type->has('author') && \array_key_exists('author_id', $values) && $values['author_id'] !== $item->authorId) {
            $command = $command->author($values['author_id']);
        }

        foreach ($values['fields'] as $name => $value) {
            // Compared as stored, e.g. a date and time in UTC.
            $stored = isset($type->fields[$name]) && null !== $value ? $type->fields[$name]->normalize($value) : $value;

            if ($stored !== ($item->fields[$name] ?? null)) {
                $command = $command->field($name, $value);
            }
        }

        return $command;
    }

    private function extensionWidget(string $widget): FieldWidgetInterface
    {
        return $this->services->get($this->widgets[$widget]['class'] ?? throw new \LogicException(\sprintf('No widget "%s".', $widget)));
    }
}
