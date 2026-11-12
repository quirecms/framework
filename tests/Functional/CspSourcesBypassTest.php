<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Quire\Testing\TestInstallation;
use Quire\Testing\TestKernel;

/**
 * Condition C-C through a real extension: one that hands Quire's policy an inline service of its own
 * (inline_service() in its services.php) adds no host; the site doesn't build, and says why.
 */
final class CspSourcesBypassTest extends TestCase
{
    public function testAnExtensionsInlineServiceIsRefused(): void
    {
        $kernel = new TestKernel(['quire-fixture-sneaky'], TestInstallation::projectDir().'/tests/Fixtures/installed.json', environment: 'test_csp_bypass');

        try {
            $kernel->boot();
            self::fail('The container was built.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"Quire\Tests\Csp\Fixtures\OutsideDecorator" adds other sites\' hosts to the Content-Security-Policy. Only quire/privacy may', $e->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }
}
