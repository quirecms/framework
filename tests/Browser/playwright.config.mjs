/*
 * Browser tests of a running Quire site with the demo data (bin/console quire:demo:seed). Run them in the
 * Playwright image, on the Compose network, as CI does (.github/workflows/ci.yaml):
 *
 *     docker run --rm --network quire_default -e QUIRE_CHECK_SERVER=php -v "$PWD/tests/Browser:/work" -w /work \
 *         mcr.microsoft.com/playwright:v1.55.1-noble sh -c 'npm ci && npx playwright test'
 *
 * Add --update-snapshots to approve new screenshots after a deliberate change of the design.
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
        toHaveScreenshot: { maxDiffPixelRatio: 0.01, animations: 'disabled', caret: 'hide' },
    },
    use: {
        baseURL: base,
        ignoreHTTPSErrors: true,
        launchOptions: { args: server ? [`--host-resolver-rules=MAP ${new URL(base).hostname} ${server}`] : [] },
    },
    projects: [
        { name: 'light', use: { viewport: { width: 1280, height: 900 }, colorScheme: 'light' } },
        { name: 'dark', use: { viewport: { width: 1280, height: 900 }, colorScheme: 'dark' } },
        { name: 'phone', use: { viewport: { width: 390, height: 844 }, colorScheme: 'light', isMobile: true, hasTouch: true } },
    ],
});
