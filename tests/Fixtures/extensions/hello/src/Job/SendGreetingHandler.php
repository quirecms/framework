<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Job;

/**
 * Registered with Extend\Jobs::handler(), without an attribute.
 */
final class SendGreetingHandler
{
    /** @var list<string> */
    public static array $greeted = [];

    public function __invoke(SendGreeting $job): void
    {
        if ('nobody' === $job->name) {
            throw new \RuntimeException('Nobody to greet.');
        }

        self::$greeted[] = $job->name;
    }
}
