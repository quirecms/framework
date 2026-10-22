<?php

declare(strict_types=1);

use Quire\Extend;

/*
 * Quire's default theme. Child themes inherit its templates, stylesheet and settings: add a stylesheet of
 * your own to change the look, and override the templates you want to change.
 */
return [
    // Explicitly this theme's file, so a child theme's stylesheet adds to it instead of replacing it.
    new Extend\Assets()
        ->stylesheet('quire-theme-default/style.css'),

    new Extend\Settings()
        ->define('theme.quire-theme-default.accent', 'enum', 'blue', label: 'Accent colour', help: 'The colour of links and highlights. Every choice is readable in light and dark mode.', group: 'appearance', choices: ['blue', 'green', 'purple', 'orange', 'red'])
        ->define('theme.quire-theme-default.show_dates', 'bool', true, label: 'Show dates', help: 'Show the publication date of posts.', group: 'appearance'),
];
