<?php

declare(strict_types=1);

/*
 * Customise this site with the same extenders that extensions use, without writing an extension.
 *
 * This file runs after every enabled extension, so it can also replace what they register, for example
 * a route with the same name. See docs/extensions.md for the available extenders.
 *
 *     return [
 *         new Quire\Extend\Routes()->get('/about', 'site_about', App\Controller\AboutController::class),
 *         new Quire\Extend\Views()->global('company', 'YorvoLabs'),
 *     ];
 */

return [
    // The site's own JavaScript and styles: assets/app.js, an entrypoint of importmap.php.
    new Quire\Extend\Assets()->entrypoint('app'),
];
