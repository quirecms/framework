<?php

declare(strict_types=1);

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
        ->controllers('Quire\\Fixtures\\Hello\\Controller\\', __DIR__.'/src/Controller', prefix: '/_fixture'),

    new Extend\Views()
        ->namespace('hello', __DIR__.'/templates')
        ->global('hello_greeting', 'Hello'),

    new Extend\Events()
        ->listen(ResponseEvent::class, Hello\FixtureHeaderListener::class),

    new Extend\Console()
        ->command(Hello\HelloCommand::class),

    new Extend\Settings()
        ->define('quire-fixture-hello.greeting', 'string', 'Hello', label: 'Greeting')
        ->define('quire-fixture-hello.shout', 'bool', false, label: 'Shout the greeting'),

    new Extend\Conditional()
        ->whenExtensionEnabled('quire-fixture-not-installed', static fn (): array => [
            new Extend\Routes()->get('/_fixture/never', 'fixture_never', Hello\HelloController::class),
        ]),
];
