<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Dashboard\WidgetInterface;

final class GreetingsWidget implements WidgetInterface
{
    public function title(): string
    {
        return 'Greetings';
    }

    public function render(): string
    {
        return '<p class="fixture-greetings">3 greetings today</p>';
    }
}
