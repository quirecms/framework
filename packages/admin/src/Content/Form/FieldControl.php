<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Form;

use Twig\Markup;

/**
 * Where a field's control goes in the editor's form: its name, its ID (the label points at it), and
 * the help and error it's described by.
 *
 * @api
 */
final readonly class FieldControl
{
    /**
     * @param string      $name        The name of the input, e.g. "fields[rating]"; add "[]" for several values
     * @param string      $id          The ID of the input, which the field's label is for
     * @param string|null $describedBy IDs of the help and the error, for aria-describedby
     */
    public function __construct(
        public string $name,
        public string $id,
        public bool $required = false,
        public bool $invalid = false,
        public ?string $describedBy = null,
    ) {
    }

    /**
     * The attributes every control should have: name, id, required, aria-invalid and aria-describedby.
     *
     *     sprintf('<input type="color" %s value="%s">', $control->attributes(), htmlspecialchars($value))
     *
     * @param string $suffix Appended to the name, e.g. "[]" for a list of values
     */
    public function attributes(string $suffix = ''): Markup
    {
        $html = \sprintf('name="%s" id="%s"', $this->escape($this->name.$suffix), $this->escape($this->id));

        if ($this->required) {
            $html .= ' required';
        }

        if ($this->invalid) {
            $html .= ' aria-invalid="true"';
        }

        if (null !== $this->describedBy) {
            $html .= \sprintf(' aria-describedby="%s"', $this->escape($this->describedBy));
        }

        return new Markup($html, 'UTF-8');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
    }
}
