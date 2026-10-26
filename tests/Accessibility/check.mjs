/*
 * Checks pages of a running site with axe-core against WCAG 2.2 A and AA, in light and dark mode, at
 * desktop width and at 320px, where nothing may be wider than the screen (reflow, WCAG 1.4.10, which
 * axe doesn't test), and that the first Tab reaches the skip link. Exits with 1 on violations.
 *
 *     node check.mjs https://quire.localhost / /blog/hello-world /about
 *
 * With QUIRE_CHECK_LOGIN="username:password", it signs in first, e.g. to check the admin's pages.
 *
 * With QUIRE_CHECK_SERVER="php", it reaches the site at that host, e.g. a Compose service, while the
 * URLs keep the site's name. The browser can then run on the Compose network instead of the host's,
 * where interfaces that come and go, e.g. when a container restarts, abort its page loads.
 *
 * Run it in the Playwright image, which has the browsers (see .github/workflows/ci.yaml).
 */
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const [base = 'https://quire.localhost', ...paths] = process.argv.slice(2);
const pages = paths.length > 0 ? paths : ['/'];
const server = process.env.QUIRE_CHECK_SERVER;
const browser = await chromium.launch({ args: server ? [`--host-resolver-rules=MAP ${new URL(base).hostname} ${server}`] : [] });
let violations = 0;

for (const colorScheme of ['light', 'dark']) {
    for (const width of [1280, 320]) {
        const context = await browser.newContext({ ignoreHTTPSErrors: true, colorScheme, viewport: { width, height: 900 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(String(error)));

        if (process.env.QUIRE_CHECK_LOGIN) {
            const [username, ...password] = process.env.QUIRE_CHECK_LOGIN.split(':');
            await page.goto(base + '/login');
            await page.fill('#username', username);
            await page.fill('#password', password.join(':'));
            await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
        }

        for (const path of pages) {
            const response = await page.goto(base + path, { waitUntil: 'networkidle' });

            if (!response || !response.ok()) {
                console.log(`✗ ${path}: HTTP ${response?.status()}`);
                violations++;
                continue;
            }

            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();

            for (const violation of results.violations) {
                violations++;
                console.log(`✗ ${path} (${colorScheme}, ${width}px): ${violation.id}: ${violation.help}`);
                violation.nodes.slice(0, 3).forEach((node) => console.log(`    ${node.target.join(' ')}`));
            }

            // Content may scroll inside its own box (e.g. a wide table), never the whole page.
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

            if (overflow > 1) {
                violations++;
                console.log(`✗ ${path} (${colorScheme}, ${width}px): reflow: the page is ${overflow}px wider than the screen`);
            }

            if (results.violations.length === 0 && overflow <= 1) {
                console.log(`✓ ${path} (${colorScheme}, ${width}px): ${results.passes.length} rules passed`);
            }
        }

        for (const error of errors) {
            violations++;
            console.log(`✗ JavaScript error (${colorScheme}, ${width}px): ${error}`);
        }

        await page.goto(base + pages[0]);
        await page.keyboard.press('Tab');

        if (!(await page.evaluate(() => document.activeElement?.matches('.skip-link, .q-skip')))) {
            violations++;
            console.log(`✗ ${pages[0]} (${colorScheme}, ${width}px): the first Tab doesn't reach the skip link`);
        }

        await context.close();
    }
}

await browser.close();
console.log(violations === 0 ? 'No accessibility problems found.' : `${violations} accessibility problem(s) found.`);
process.exit(violations === 0 ? 0 : 1);
