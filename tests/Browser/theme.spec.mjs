/*
 * The default theme's pages as a visitor sees them, compared with the approved screenshots in screenshots/.
 * The demo site is dated relative to when it was made (DemoCalendar keeps its months' shape), so what says
 * a date is masked: times, day numbers, the week, kickers, month headings and links, the archive's line,
 * the history and the byline. The 404 page is Symfony's error preview, which renders the theme's page.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

// Playwright applies screenshot.css as an inline style, which the theme's policy (style-src 'self') refuses:
// without this, its rules (each masked line takes a whole line) never reach these pages, and a mask follows
// the length of the date it hides.
test.use({ bypassCSP: true });

// The design's screens a visitor reaches on the demo site, by the handoff's numbers (handoff-v7.json says
// what covers each of the others)
const screens = {
    'theme-home': '/',                                  // 01
    'theme-all-posts': '/blog',                         // 02a
    'theme-archive': '/blog?page=2',                    // 02
    'theme-post': '/blog/spring-draw-all-groups',       // 03
    'theme-child-page': '/about/tickets',               // 04
    'theme-page': '/about',                             // 04b, a page with its section
    'theme-404': '/_error/404',                         // 05b
    'theme-error': '/_error/500',                       // 06
    'theme-sign-in': '/login',                          // 08a
};

const mask = (page) => [page.locator('time, .qd-daynum, .qd-week, .qd-kicker, .qd-month__head, nav.qd-months, .qd-list-head .qd-meta, .qd-history, .qd-byline, .qd-page-meta, nav.qd-crumbs, .qd-browse, .qd-section-map .qd-sub, .qd-site-footer .qd-base, .qd-rows, .qd-reference')];

for (const [name, path] of Object.entries(screens)) {
    test(name, async ({ page }) => {
        await page.goto(path, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await page.mouse.move(0, 0);

        await expect(page).toHaveScreenshot(`${name}.png`, {
            fullPage: true,
            mask: mask(page),
            stylePath: new URL('./screenshot.css', import.meta.url).pathname,
        });
    });
}

// The screens that need a visitor's action or a session: 08a2, 08i, 07a
const shoot = async (page, name) => {
    await page.evaluate(() => document.fonts.ready);
    await page.mouse.move(0, 0);
    await expect(page).toHaveScreenshot(`${name}.png`, { fullPage: true, mask: mask(page), stylePath: new URL('./screenshot.css', import.meta.url).pathname });
};

test('theme-sign-in-error', async ({ page }, testInfo) => {
    await page.goto('/login');
    // One unknown name per look: sign-in throttling counts failures per name, so runs never add up to "Too many"
    await page.fill('#username', `nobody-${testInfo.project.name}`);
    await page.fill('#password', 'not-the-password');
    await Promise.all([page.waitForURL(/\/login$/), page.click('form:has(#password) button[type=submit]')]);
    await shoot(page, 'theme-sign-in-error');
});

test('theme-signed-in', async ({ page }) => {
    await signIn(page);
    await page.goto('/login', { waitUntil: 'networkidle' });
    await shoot(page, 'theme-signed-in');
});

test('theme-preview', async ({ page }) => {
    await signIn(page);
    await page.goto('/blog/referee-assignments-explained', { waitUntil: 'networkidle' });
    await shoot(page, 'theme-preview');
});

// A rule whose selector the browser can't read is dropped without a word: the pager's numbers once lost all
// their styles that way ("[data-kind="num"]bers"). Every block of the theme's stylesheet must become a rule.
test('every rule of the theme\'s and the banner\'s stylesheets is one the browser reads', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'light', 'The same in every look');
    await page.goto('/', { waitUntil: 'networkidle' });

    const sheets = await page.evaluate(async () => {
        // A layer order statement ("@layer a, b;") is a rule without a block
        const count = (rules) => [...rules].reduce((n, rule) => n + (rule instanceof CSSLayerStatementRule ? 0 : 1) + (rule.cssRules ? count(rule.cssRules) : 0), 0);
        const results = [];
        for (const link of document.querySelectorAll('link[rel=stylesheet]')) {
            if (!link.href.includes('quire-theme-default/') && !link.href.includes('quire-privacy/')) {
                continue;
            }
            // Blocks in the source, without comments and strings (a "{" in content: "…" isn't one)
            const source = (await (await fetch(link.href)).text()).replace(/\/\*[\s\S]*?\*\//g, '').replace(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, '""');
            results.push({ href: new URL(link.href).pathname, blocks: (source.match(/\{/g) ?? []).length, rules: count(link.sheet.cssRules) });
        }

        return results;
    });

    expect(sheets.length, 'the theme\'s stylesheet is on the page').toBeGreaterThan(0);
    for (const sheet of sheets) {
        expect(sheet.rules, `${sheet.href}: rules the browser read, against blocks in the file`).toBe(sheet.blocks);
    }
});
