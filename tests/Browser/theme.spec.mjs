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
