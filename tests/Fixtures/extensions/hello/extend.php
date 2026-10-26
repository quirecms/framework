<?php

declare(strict_types=1);

use League\CommonMark\Extension\Footnote\FootnoteExtension;
use Quire\Admin\Extend\Admin;
use Quire\Extend;
use Quire\Fixtures\Hello;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/*
 * A test extension that uses every extender, enabled in the test environment
 * (see "when@test" in config/packages/quire.yaml).
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Fixtures\\Hello\\', __DIR__.'/src', exclude: [__DIR__.'/src/Controller']),

    new Extend\Routes()
        ->get('/_fixture/hello/{name}', 'fixture_hello', Hello\HelloController::class, defaults: ['name' => 'world'])
        ->get('/_fixture/posts', 'fixture_posts', Hello\PostsController::class)
        ->controllers('Quire\\Fixtures\\Hello\\Controller\\', __DIR__.'/src/Controller', prefix: '/_fixture'),

    new Extend\Views()
        ->namespace('hello', __DIR__.'/templates')
        ->global('hello_greeting', 'Hello')
        ->slot('content.after_body', '@hello/after_body.html.twig')
        ->slot('content.after_body', Hello\Theme\LikesSlot::class, priority: 10)
        ->slot('footer', '@hello/footer.html.twig')
        ->slot('auth.login_options', '@hello/login_option.html.twig'),

    new Extend\Assets()
        ->stylesheet('quire-fixture-hello/hello.css')
        ->script('quire-fixture-hello/hello.js')
        ->stylesheet('quire-fixture-hello/admin.css', group: 'admin'),

    new Extend\Routes(group: 'admin')
        ->get('/greetings', 'fixture_hello_admin', Hello\Admin\GreetingsController::class),

    // The admin's own extender: a menu entry for the page above, what waits for moderators, and a card on Home.
    new Admin()
        ->menu('quire-fixture-hello.greetings', 'Greetings', 'fixture_hello_admin', ability: 'quire-fixture-hello.moderate')
        ->inbox(Hello\Admin\GreetingsInbox::class, ability: 'quire-fixture-hello.moderate')
        ->widget(Hello\Admin\GreetingsWidget::class, ability: 'quire-fixture-hello.moderate', order: 5)
        ->contentColumn('quire-fixture-hello.likes', Hello\Admin\LikesColumn::class, types: ['post'])
        ->contentFilter('quire-fixture-hello.featured', Hello\Admin\FeaturedFilter::class, types: ['post'])
        ->contentAction('quire-fixture-hello.feature', Hello\Admin\FeatureAction::class, types: ['post'])
        ->contentNextStep('quire-fixture-hello.review', Hello\Admin\ReviewStep::class, types: ['post'])
        ->fieldWidget('color', Hello\Admin\ColorWidget::class),

    new Extend\ContentChecks()
        ->check('quire-fixture-hello.shouting', Hello\Check\ShoutingCheck::class, 'Titles aren\'t shouted', types: ['post']),

    new Extend\Events()
        ->listen(ResponseEvent::class, Hello\FixtureHeaderListener::class),

    new Extend\Console()
        ->command(Hello\HelloCommand::class),

    new Extend\Migrations('Quire\\Fixtures\\Hello\\Migrations', __DIR__.'/migrations'),

    new Extend\Permissions()
        ->role('moderator', 'Moderator')
        ->ability('quire-fixture-hello.moderate', 'Moderate greetings', roles: ['moderator', 'editor'])
        ->ability('quire-fixture-hello.purge', 'Delete all greetings for good', roles: [], strict: true)
        ->rule(Hello\Permission\LockedContentRule::class),

    new Extend\Storage()
        ->disk('quire-fixture-hello', public: true, label: 'Greeting pictures')
        ->disk('quire-fixture-hello-private', public: false, label: 'Greeting downloads'),

    new Extend\Auth()
        ->authenticator(Hello\Security\SsoAuthenticator::class),

    new Extend\Jobs()
        ->handler(Hello\Job\SendGreetingHandler::class)
        ->schedule('quire-fixture-hello.tick', 'every 5 minutes', Hello\Job\Tick::class),

    new Extend\Settings()
        ->define('quire-fixture-hello.greeting', 'string', 'Hello', label: 'Greeting', exposed: true)
        ->define('quire-fixture-hello.shout', 'bool', false, label: 'Shout the greeting'),

    new Extend\ContentType('greeting_card')
        ->label('Greeting card')
        ->capabilities('listable', 'printable')
        ->field('recipient', 'string', required: true, filterable: true, label: 'Recipient')
        ->field('posts', 'relation', options: ['types' => ['post'], 'limit' => 3, 'inverse' => 'cards'])
        ->field('reply_to', 'relation', options: ['types' => ['greeting_card'], 'multiple' => false])
        ->permalink('/cards/{id}'),

    Extend\ContentType::existing('page')
        ->field('related', 'relation', options: ['types' => ['page', 'post']]),

    new Extend\FieldTypes()
        ->type('quire-fixture-hello.color', Hello\Field\ColorField::class),

    Extend\ContentType::existing('post')
        ->addCapabilities('greetable')
        ->field('mood', 'enum', choices: ['happy', 'sad'])
        ->field('rating', 'int', min: 1, max: 5, sortable: true)
        ->field('score', 'float', min: 0)
        ->field('featured', 'bool', default: false, filterable: true)
        ->field('subtitle', 'string', maxLength: 100)
        ->field('color', 'string')
        ->field('size', 'string')
        ->field('tags', 'list', filterable: true)
        ->field('due', 'date')
        ->field('event_at', 'datetime', sortable: true)
        ->field('extra', 'json')
        ->field('accent', 'quire-fixture-hello.color', filterable: true, sortable: true, options: ['palette' => ['#F80', '#0055aa', '#222222']]),

    new Extend\Query()
        ->scope('featured', Hello\Query\FeaturedScope::class, types: ['post'])
        ->scope('tagged', Hello\Query\TaggedScope::class, types: ['post'])
        ->scope('popular', Hello\Query\PopularScope::class)
        ->visibility(Hello\Query\HideUnaddressedCards::class, types: ['greeting_card'])
        ->relation('likes', Hello\Query\LikesLoader::class, types: ['post']),

    new Extend\Formatter()
        ->shortcode('greeting', Hello\Formatter\GreetingShortcode::class)
        ->shortcode('embed', Hello\Formatter\EmbedShortcode::class, trusted: true)
        ->commonMark(FootnoteExtension::class, profiles: ['content'])
        ->preProcessor(Hello\Formatter\WaveProcessor::class)
        ->postProcessor(Hello\Formatter\LazyImages::class, profiles: ['content'])
        ->profile('review', formats: ['markdown'], shortcodes: ['greeting'], sanitizer: 'basic', linkRel: ['nofollow']),

    new Extend\ContentWorkflow()
        ->status('in_review')
        ->transition('submit', from: ['draft'], to: 'in_review')
        ->transition('publish', from: ['in_review'], to: 'published'),

    new Extend\Conditional()
        ->whenExtensionEnabled('quire-fixture-not-installed', static fn (): array => [
            new Extend\Routes()->get('/_fixture/never', 'fixture_never', Hello\HelloController::class),
        ]),
];
