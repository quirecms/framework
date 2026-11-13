/*
 * Browser tests of a running Quire site with the demo data (bin/console quire:demo:seed). Run them in the
 * Playwright image, on the Compose network, as CI does (.github/workflows/ci.yaml):
 *
 *     docker run --rm --network quire_default -e QUIRE_CHECK_SERVER=php -v "$PWD/tests/Browser:/work" -w /work \
 *         mcr.microsoft.com/playwright:v1.55.1-noble sh -c 'npm ci && npx playwright test'
 *
 * Add --update-snapshots=all to approve new screenshots after a deliberate change of the design (without
 * "=all", only those that differ by more than the tolerance are replaced), on a freshly seeded demo site:
 * the browser flows change its data (e.g. Home's count of what waits), so screenshots taken after them
 * are wrong. Look at each one before committing it. visual-tolerance.spec.mjs is skipped then.
 */
import { defineConfig } from '@playwright/test';

const base = process.env.QUIRE_CHECK_BASE ?? 'https://quire.localhost';
const server = process.env.QUIRE_CHECK_SERVER;

export default defineConfig({
    testDir: '.',
    // One image per screen and variant, the same on every machine: they're taken in the same image.
    snapshotPathTemplate: '{testDir}/screenshots/{arg}-{projectName}{ext}',
    fullyParallel: true,
    retries: 0,
    reporter: [['list']],
    expect: {
        // At most 50 pixels may differ: screens taken again on a fresh demo site differ by none, and the
        // smallest change the comparison must catch (a button left hovered) is about 230
        // (visual-tolerance.spec.mjs checks that it does). A share of the page (1%) once let a whole new
        // card and a whole new tab through.
        toHaveScreenshot: { maxDiffPixels: 50, animations: 'disabled', caret: 'hide' },
    },
    use: {
        baseURL: base,
        ignoreHTTPSErrors: true,
        launchOptions: { args: server ? [`--host-resolver-rules=MAP ${new URL(base).hostname} ${server}`] : [] },
    },
    projects: [
        { name: 'light', testIgnore: /flows|together/, use: { viewport: { width: 1280, height: 900 }, colorScheme: 'light' } },
        { name: 'dark', testIgnore: /flows|together/, use: { viewport: { width: 1280, height: 900 }, colorScheme: 'dark' } },
        { name: 'phone', testIgnore: /flows|together/, use: { viewport: { width: 390, height: 844 }, colorScheme: 'light', isMobile: true, hasTouch: true } },
        // The flows change the demo site, so they run after the screenshots were compared with it. Run them
        // again on a fresh demo site.
        { name: 'flows', testMatch: /(^|\/)flows\.spec\.mjs$/, dependencies: ['light', 'dark', 'phone'], use: { viewport: { width: 1280, height: 900 }, colorScheme: 'light' } },
        { name: 'phone-flows', testMatch: /phone-flows\.spec\.mjs/, dependencies: ['flows'], use: { viewport: { width: 390, height: 844 }, colorScheme: 'light', isMobile: true, hasTouch: true } },
        // Two people editing one item at once (D-41): it publishes, so after everything else.
        { name: 'together', testMatch: /together\.spec\.mjs/, dependencies: ['phone-flows'], use: { viewport: { width: 1280, height: 900 }, colorScheme: 'light' } },
    ],
});
