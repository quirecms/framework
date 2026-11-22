<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every screen of the default theme's design is accounted for (handoff-v7.json): by a screenshot the
 * browser suite compares in each look, a test that exists, a browser check that exists, or a reason it
 * isn't built yet. A screen of the design without one, or one whose screenshot or test was removed, fails
 * here, so "built" can't be said of a screen nothing shows.
 */
final class HandoffCoverageTest extends TestCase
{
    /** The design's pages, as its handoff numbers them */
    private const array SCREENS = ['01-home', '01b-home-empty-visitor', '01c-home-empty-owner', '01e-home-no-dates', '02-list-page-2', '02a-all-posts', '02b-list-last', '03-single-post', '03b-single-post-short', '03c-single-post-corrected', '03d-single-post-external-image', '03e-single-post-long-history', '03f-single-post-results', '04-single-page', '04b-parent-page', '05-404', '05b-404-no-match', '06-error', '07a-preview-notice', '07b-view-as-bar', '07c-theme-preview-bar', '07d-preview-changes', '07e-preview-many-changes', '08a-sign-in', '08a2-sign-in-error', '08b-register', '08c-forgot-password', '08d-check-inbox', '08e-choose-password', '08f-accept-invitation', '08f2-accept-invitation-no-inviter', '08g-link-expired', '08h-confirm-email', '08i-signed-in', '08j-register-check-inbox', '09a-banner', '09b-banner-corner', '09c-cookie-dialog', '09d-embed', '09e-privacy-page', '10-accents', '11-rtl-arabic', '12-german', '13-stress-home', '13b-sidebar-slot', '14-interaction-states', '15-macedonian'];

    public function testEveryScreenOfTheDesignIsShownToBeBuilt(): void
    {
        $root = \dirname(__DIR__, 3);
        /** @var array{screens: array<string, array<string, mixed>>} $handoff */
        $handoff = json_decode((string) file_get_contents(__DIR__.'/handoff-v7.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(self::SCREENS, array_keys($handoff['screens']), 'Each screen of the design, in its order, once.');

        foreach ($handoff['screens'] as $screen => $coverage) {
            self::assertCount(1, $coverage, $screen.': one kind of evidence.');

            match (array_key_first($coverage)) {
                'screenshot' => $this->assertScreenshots($root, $screen, $coverage['screenshot']),
                'test' => $this->assertTest($screen, $coverage['test']),
                'check' => $this->assertCheck($root, $screen, $coverage['check']),
                'deferred' => self::assertIsString($coverage['deferred'], $screen.': why it isn\'t built yet.'),
                default => self::fail($screen.': '.array_key_first($coverage).' isn\'t a kind of evidence.'),
            };
        }
    }

    private function assertScreenshots(string $root, string $screen, mixed $name): void
    {
        self::assertIsString($name);
        foreach (['light', 'dark', 'phone'] as $look) {
            self::assertFileExists($root.'/tests/Browser/screenshots/'.$name.'-'.$look.'.png', $screen.': its screenshot in '.$look.'.');
        }
        self::assertStringContainsString("'".$name."'", (string) file_get_contents($root.'/tests/Browser/theme.spec.mjs'), $screen.': the browser suite takes it.');
    }

    private function assertTest(string $screen, mixed $test): void
    {
        self::assertIsString($test);
        [$class, $method] = explode('::', $test) + [1 => ''];
        self::assertTrue(class_exists($class) && method_exists($class, $method), $screen.': '.$test.' exists.');
    }

    private function assertCheck(string $root, string $screen, mixed $check): void
    {
        self::assertIsArray($check);
        [$file, $name] = $check;
        self::assertIsString($file);
        self::assertIsString($name);
        self::assertMatchesRegularExpression('/check\\([\'"`]'.preg_quote($name, '/').'/', (string) file_get_contents($root.'/'.$file), $screen.': the check "'.$name.'…" exists in '.$file.'.');
    }
}
