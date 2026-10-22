<?php

declare(strict_types=1);

use Quire\Extend;

return [
    new Extend\Views()
        ->global('fixture_child_extend_ran', true),

    // Same name as the parent's setting: the child's wins in theme.settings.
    new Extend\Settings()
        ->define('theme.quire-fixture-child.accent', 'string', 'green', label: 'Accent colour'),
];
