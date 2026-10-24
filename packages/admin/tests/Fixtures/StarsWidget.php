<?php

declare(strict_types=1);

namespace Quire\Admin\Tests\Fixtures;

use Quire\Admin\Content\Form\FieldControl;
use Quire\Admin\Content\Form\FieldWidgetInterface;
use Quire\Content\Field\FieldDefinition;

final class StarsWidget implements FieldWidgetInterface
{
    public function render(FieldDefinition $field, mixed $value, FieldControl $control): string
    {
        return '★★★';
    }

    public function value(FieldDefinition $field, mixed $submitted): mixed
    {
        return $submitted;
    }
}
