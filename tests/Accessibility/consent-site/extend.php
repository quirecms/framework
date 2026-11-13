<?php

declare(strict_types=1);

use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

/*
 * CI only: the site's extend.php while consent.mjs checks the banner, with two services of different
 * categories (none of them analytics), and trusted shortcodes (src/Ci): [[ci_video]] embedding the
 * video from the site's own host, [[ci_probe]] and [[ci_event]]. Google Analytics 4 (quire/analytics) is
 * turned on by the step's setting; consent.mjs answers Google's hosts itself. The step puts these in
 * place of the site's own and back.
 */
return [
    new Quire\Extend\Assets()->entrypoint('app'),

    new Privacy()
        ->service('ci-video', label: 'CI Video', category: Category::Marketing, vendor: 'CI Video Ltd', purpose: 'Plays the videos in posts.', cookies: [new Cookie('ci_video_id', '1 year', 'Recognises you across sites.')], hosts: ['frame-src' => ['https://player.video.example', 'https://quire.localhost'], 'script-src' => ['https://quire.localhost']], retention: '1 year', policyUrl: 'https://video.example/privacy', script: 'ci-consent/start.js')
        ->service('ci-fonts', label: 'CI Fonts', category: Category::Preferences, vendor: 'CI Fonts Inc.', purpose: 'Serves the site\'s fonts.', hosts: ['font-src' => ['https://fonts.example']]),

    new Quire\Extend\Formatter()
        ->shortcode('ci_video', App\Ci\CiVideoShortcode::class, trusted: true)
        ->shortcode('ci_probe', App\Ci\CiProbeShortcode::class, trusted: true)
        ->shortcode('ci_event', App\Ci\CiEventShortcode::class, trusted: true),
];
