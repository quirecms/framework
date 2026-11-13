<?php

declare(strict_types=1);

use Quire\Extend;
use Quire\Fixtures\Video\VideoShortcode;
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

/*
 * A test extension with services that visitors are asked about, none of them analytics (doc 20, A7):
 * an embedded video (marketing) and a font service (preferences); and a necessary one that reaches
 * another site, which the owner is asked to check (a form's spam check). It requires quire-privacy.
 */
return [
    new Privacy()
        ->service(
            'fixture-video',
            label: 'Fixture Video',
            category: Category::Marketing,
            vendor: 'Fixture Video Ltd',
            purpose: 'Plays the videos in posts.',
            cookies: [new Cookie('fixture_video_id', '1 year', 'Recognises you across sites.'), new Cookie('fixture_video_pref_*', 'The session', 'Remembers the player\'s volume.')],
            hosts: ['frame-src' => ['https://player.video.example'], 'img-src' => ['https://thumbs.video.example']],
            retention: '1 year',
            policyUrl: 'https://video.example/privacy',
            script: 'quire-fixture-video/start.js',
            config: ['channel' => 'quire'],
        )
        ->service(
            'fixture-fonts',
            label: 'Fixture Fonts',
            category: Category::Preferences,
            vendor: 'Fixture Fonts Inc.',
            purpose: 'Serves the site\'s fonts.',
            hosts: ['style-src' => ['https://fonts.example'], 'font-src' => ['https://fonts.example']],
        )
        ->service(
            'fixture-spam-check',
            label: 'Fixture Spam Check',
            category: Category::Necessary,
            vendor: 'Fixture Spam Check GmbH',
            purpose: 'Keeps spam out of the contact form.',
            hosts: ['script-src' => ['https://spam-check.example']],
        ),

    // [[fixture_video id=…]]: a trusted shortcode embedding the video, held until the visitor allows it.
    new Extend\Formatter()
        ->shortcode('fixture_video', VideoShortcode::class, trusted: true),
];
