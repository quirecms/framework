<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Field;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;

/**
 * A color as "#rrggbb", given in any case and as "#rgb" too, optionally one of a palette.
 */
final class ColorField extends AbstractFieldType
{
    public function options(): array
    {
        return ['palette'];
    }

    public function definitionError(FieldDefinition $field): ?string
    {
        $palette = $field->options['palette'] ?? [];

        if (!\is_array($palette) || !array_all($palette, fn (mixed $color): bool => null === $this->validate($this->normalize($color, $field), $field))) {
            return 'The palette is a list of colors such as "#ff8800".';
        }

        return null;
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        if (!\is_string($value) || 1 !== preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
            return $value;
        }

        $hex = strtolower(substr($value, 1));

        return '#'.(3 === \strlen($hex) ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex);
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return 'Expected a color such as "#ff8800".';
        }

        $palette = $field->options['palette'] ?? [];

        return \is_array($palette) && [] !== $palette && !\in_array($value, array_map(fn (mixed $color): mixed => $this->normalize($color, $field), $palette), true) ? 'Expected one of the palette\'s colors.' : null;
    }

    public function expected(FieldDefinition $field): string
    {
        return 'a color such as "#ff8800"';
    }

    public function widget(FieldDefinition $field): string
    {
        return 'color';
    }
}
