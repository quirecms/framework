<?php

declare(strict_types=1);

namespace Quire\Admin\Tests\Fixtures;

use Quire\Admin\Dashboard\WidgetInterface;

final class NoticesWidget implements WidgetInterface
{
    public function title(): string
    {
        return 'Notices';
    }

    public function render(): string
    {
        return '';
    }
}
