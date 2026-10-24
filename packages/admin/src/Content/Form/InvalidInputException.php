<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Form;

/**
 * What was entered for a field can't be read as a value, e.g. JSON with a syntax error. The message,
 * translated, is shown next to the field.
 *
 * @api
 */
final class InvalidInputException extends \InvalidArgumentException
{
}
