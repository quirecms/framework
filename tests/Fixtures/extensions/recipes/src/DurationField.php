<?php

declare(strict_types=1);

namespace Quire\Fixtures\Recipes;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * A duration in minutes, given as minutes ("90") or hours and minutes ("1h 30m", "1:30"). Stored as a
 * number, so it sorts; edited as text, with the admin's text widget.
 */
final class DurationField extends AbstractFieldType
{
    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Number;
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        if (\is_int($value)) {
            return $value;
        }

        $text = \is_string($value) ? strtolower(trim($value)) : '';

        return match (true) {
            1 === preg_match('/^\d+$/', $text) => (int) $text,
            1 === preg_match('/^(\d+):([0-5]\d)$/', $text, $m) => (int) $m[1] * 60 + (int) $m[2],
            1 === preg_match('/^(?:(\d+)\s*h)?\s*(?:(\d+)\s*m(?:in)?)?$/', $text, $m) && '' !== $text => (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0),
            default => $value,
        };
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_int($value) && $value >= 1 && $value <= 24 * 60 ? null : 'Expected a duration such as "1h 30m" or "45", up to a day.';
    }

    public function expected(FieldDefinition $field): string
    {
        return 'a duration such as "1h 30m"';
    }

    public function widget(FieldDefinition $field): string
    {
        return 'text';
    }
}
