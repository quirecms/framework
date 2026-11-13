<?php

declare(strict_types=1);

use Quire\Analytics\Internal\NotCounted;
use Quire\Extend;

/*
 * Analytics: Google Analytics 4 through quire/privacy, so visitors are asked first. The site turns it
 * on in its own extend.php, with its measurement ID:
 *
 *     new Quire\Analytics\Extend\Analytics()->ga4('G-ABC123DEF4'),
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Analytics\\', __DIR__.'/src', exclude: [
            __DIR__.'/src/Extend',
        ]),

    // Previews, a theme being tried and error pages aren't counted.
    new Extend\Views()
        ->slot('head', NotCounted::class),
];
