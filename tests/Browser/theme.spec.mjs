/*
 * The default theme's pages as a visitor sees them, compared with the approved screenshots in screenshots/.
 * The demo site is dated relative to when it was made (DemoCalendar keeps its months' shape), so what says
 * a date is masked: times, day numbers, the week, kickers, month headings and links, the archive's line,
 * the history and the byline. The 404 page is Symfony's error preview, which renders the theme's page.
 */
import { test, expect } from '@playwright/test';

// Playwright applies screenshot.css as an inline style, which the theme's policy (style-src 'self') refuses:
// without this, its rules (each masked line takes a whole line) never reach these pages, and a mask follows
// the length of the date it hides.
test.use({ bypassCSP: true });

const screens = {
    'theme-home': '/',
    'theme-archive': '/blog?page=2',
    'theme-post': '/blog/spring-draw-all-groups',
    'theme-page': '/about',
    'theme-404': '/_error/404',
};

for (const [name, path] of Object.entries(screens)) {
    test(name, async ({ page }) => {
        await page.goto(path, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await page.mouse.move(0, 0);

        await expect(page).toHaveScreenshot(`${name}.png`, {
            fullPage: true,
            mask: [page.locator('time, .daynum, .week, .kicker, .month__head, nav.months, .list-head .meta, .history, .byline, .page-meta, nav.crumbs, .browse, .section-map .sub, .site-footer .base, .rows')],
            stylePath: new URL('./screenshot.css', import.meta.url).pathname,
        });
    });
}

// A rule whose selector the browser can't read is dropped without a word: the pager's numbers once lost all
// their styles that way ("[data-kind="num"]bers"). Every block of the theme's stylesheet must become a rule.
test('every rule of the theme\'s stylesheet is one the browser reads', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'light', 'The same in every look');
    await page.goto('/', { waitUntil: 'networkidle' });

    const sheets = await page.evaluate(async () => {
        const count = (rules) => [...rules].reduce((n, rule) => n + 1 + (rule.cssRules ? count(rule.cssRules) : 0), 0);
        const results = [];
        for (const link of document.querySelectorAll('link[rel=stylesheet]')) {
            if (!link.href.includes('quire-theme-default/')) {
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
