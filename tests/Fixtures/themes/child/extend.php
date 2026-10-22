<?php

declare(strict_types=1);

use Quire\Extend;

return [
    new Extend\Views()
        ->global('fixture_child_extend_ran', true),
];
