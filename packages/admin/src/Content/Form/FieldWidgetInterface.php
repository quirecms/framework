<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Form;

use Quire\Content\Field\FieldDefinition;

/**
 * How the editor shows a field and reads what was entered, for fields whose widget has this name (see
 * {@see \Quire\Content\Field\FieldTypeInterface::widget()}), added with
 * {@see \Quire\Admin\Extend\Admin::fieldWidget()}.
 *
 *     final readonly class ColorWidget implements FieldWidgetInterface
 *     {
 *         public function render(FieldDefinition $field, mixed $value, FieldControl $control): string|\Stringable
 *         {
 *             return new Markup(sprintf('<input type="color" %s value="%s">', $control->attributes(), htmlspecialchars(is_string($value) ? $value : '#000000')), 'UTF-8');
 *         }
 *
 *         public function value(FieldDefinition $field, mixed $submitted): mixed
 *         {
 *             return is_string($submitted) && '' !== $submitted ? $submitted : null;
 *         }
 *     }
 *
 * The editor adds the label, help and error around the control. The field type checks the value when
 * the item is saved.
 *
 * @api
 */
interface FieldWidgetInterface
{
    /**
     * The control, as HTML: give it {@see FieldControl::attributes()}. A string is escaped, so return a
     * \Twig\Markup, and escape what's in it.
     *
     * @param mixed $value The field's value, as stored, or as entered when the form is shown again
     */
    public function render(FieldDefinition $field, mixed $value, FieldControl $control): string|\Stringable;

    /**
     * The field's value from what the form sent for the control's name: a string, a list of strings, or
     * null when nothing was sent (e.g. an unticked checkbox). Null clears the field.
     *
     * @throws InvalidInputException when what was entered can't be read, e.g. "Enter a number."
     */
    public function value(FieldDefinition $field, mixed $submitted): mixed;
}
