<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Content\Form\FieldControl;
use Quire\Admin\Content\Form\FieldWidgetInterface;
use Quire\Admin\Content\Form\InvalidInputException;
use Quire\Content\Field\FieldDefinition;
use Twig\Markup;

/**
 * Edits the fixture's colour fields with the browser's colour picker.
 */
final class ColorWidget implements FieldWidgetInterface
{
    public function render(FieldDefinition $field, mixed $value, FieldControl $control): Markup
    {
        return new Markup(\sprintf('<input type="color" %s value="%s">', $control->attributes(), htmlspecialchars(\is_string($value) ? $value : '#000000')), 'UTF-8');
    }

    public function value(FieldDefinition $field, mixed $submitted): ?string
    {
        if (null === $submitted || '' === $submitted) {
            return null;
        }

        return \is_string($submitted) && 1 === preg_match('/^#[0-9a-f]{6}$/i', $submitted) ? $submitted : throw new InvalidInputException('Choose a colour.');
    }
}
