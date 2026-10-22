<?php

declare(strict_types=1);

use Quire\Extend;

/*
 * A test theme with a layout and a content template. Its extenders run only while it's the active theme
 * or a parent of it.
 */
return [
    new Extend\Views()
        ->global('fixture_base_extend_ran', true),

    new Extend\Settings()
        ->define('theme.quire-fixture-base.accent', 'string', 'blue', label: 'Accent colour')
        ->define('theme.quire-fixture-base.show_dates', 'bool', true, label: 'Show dates'),
];
