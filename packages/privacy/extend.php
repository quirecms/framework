<?php

declare(strict_types=1);

use Quire\Admin\Extend\Admin;
use Quire\Extend;
use Quire\Privacy\Internal\Admin\PrivacyController;
use Quire\Privacy\Internal\Formatter\ServicesShortcode;
use Quire\Privacy\Internal\Http\ConsentController;
use Quire\Privacy\Internal\Job\PurgeConsentLog;
use Quire\Privacy\Internal\SourcesSlotPass;
use Quire\Privacy\Internal\Theme;
use Quire\Privacy\PrivacySettings;

/*
 * Privacy and cookies: the services other extensions and themes declare (Quire\Privacy\Extend\Privacy),
 * the banner and settings dialog that ask visitors about them, and the hosts of those services in the
 * site's Content-Security-Policy. A site with no such service shows nothing.
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Privacy\\', __DIR__.'/src', exclude: [
            __DIR__.'/src/Category.php',
            __DIR__.'/src/Choice.php',
            __DIR__.'/src/Cookie.php',
            __DIR__.'/src/Service.php',
            __DIR__.'/src/Extend',
        ])
        ->load(__DIR__.'/config/services.php')
        ->compilerPass(new SourcesSlotPass()),

    new Extend\Views()
        ->namespace('quire_privacy', __DIR__.'/templates')
        ->slot('head', Theme\StylesheetSlot::class)
        // First in the body, after the theme's skip link: the first thing a keyboard reaches.
        ->slot('body_start', Theme\BannerSlot::class, priority: 1000)
        ->slot('footer', Theme\OpenerSlot::class, priority: -1000),

    new Extend\Settings()
        ->define(PrivacySettings::LAYOUT, 'enum', 'bar', label: 'Banner', help: 'Where visitors see it: a bar at the bottom, or a box in a corner.', choices: ['bar', 'corner'], valueLabels: ['bar' => 'A bar at the bottom', 'corner' => 'A box in a corner'])
        ->define(PrivacySettings::REJECT_WORDING, 'enum', 'reject', label: 'The button that rejects', help: 'They all mean the same: only what the site needs to work.', choices: ['reject', 'necessary', 'technical'], valueLabels: ['reject' => '"Reject …"', 'necessary' => '"Necessary only"', 'technical' => '"Technical cookies only"'])
        ->define(PrivacySettings::CHOICE_MONTHS, 'int', 6, label: 'Keep a visitor\'s choice for (months)', help: 'Then they are asked again. At most 12.')
        ->define(PrivacySettings::ASK_AGAIN_MONTHS, 'int', 6, label: 'Ask visitors who rejected again after (months)', help: 'At most 12.')
        ->define(PrivacySettings::HONOUR_SIGNALS, 'bool', true, label: 'Respect "do not track" signals', help: 'Browsers that send Global Privacy Control or Do Not Track count as rejecting, and see no banner.')
        ->define(PrivacySettings::SEND_IN_TEST, 'bool', false, label: 'Send outside production too', help: 'Services run after consent on other domains than the site\'s own and outside production too, e.g. to check a setup on a temporary domain. What they send there counts as real.')
        ->define(PrivacySettings::LOG_MONTHS, 'int', 12, label: 'Keep proof of consent for (months)', help: 'Each choice is kept with what visitors were told, then removed. At most 60.')
        ->define(PrivacySettings::PRIVACY_PAGE, 'string', '', label: 'Privacy notice', help: 'A path on the site, e.g. "/privacy", or an address: linked from the cookie settings.'),

    new Extend\Migrations('Quire\\Privacy\\Migrations', __DIR__.'/migrations'),

    // Where the consent loader logs a choice, as proof.
    new Extend\Routes()
        ->post('/_quire/privacy/consent', ConsentController::ROUTE, ConsentController::class),

    new Extend\Jobs()
        ->schedule('quire-privacy.purge', '40 3 * * *', PurgeConsentLog::class),

    // [[privacy_services]] in a page's text: the site's services, for its privacy notice.
    new Extend\Formatter()
        ->shortcode('privacy_services', ServicesShortcode::class, trusted: true),

    new Extend\Permissions()
        ->ability(PrivacyController::ABILITY, 'Manage privacy and cookies', roles: [], group: 'admin'),

    // The owner's page, when the admin is there.
    new Extend\Conditional()->whenExtensionEnabled('quire-admin', static fn (): array => [
        new Extend\Routes(group: 'admin')
            ->get('/privacy', PrivacyController::ROUTE, PrivacyController::class),
        new Extend\Assets()
            ->stylesheet('quire-privacy/admin.css', group: 'admin'),
        new Admin()
            ->menu('quire-privacy.privacy', 'Privacy and cookies', PrivacyController::ROUTE, ability: PrivacyController::ABILITY, section: 'site', order: 25),
    ]),
];
