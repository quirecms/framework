/*
 * The admin's screens, compared with the approved screenshots in screenshots/. Times and what changes
 * with them (the latest activity, Home's greeting, week and what waits since when) are masked: the demo site is dated relative to when it was made.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

const screens = {
    home: '/admin',
    posts: '/admin/content/post',
    // Not the calendar: the demo site is dated relative to when it was made, so its month changes.
    'posts-in-motion': '/admin/content/post?mode=board',
    'new-post': '/admin/content/post/new',
    // A draft with what the checks found, opened from the list: its ID changes with each demo site.
    editor: async (page) => {
        await page.goto('/admin/content/post?q=Referee+assignments', { waitUntil: 'networkidle' });
        await page.locator('.content-list__title').first().click();
        await page.waitForURL(/\/admin\/content\/post\/[0-9a-f-]{36}$/);
        await page.waitForLoadState('networkidle');
    },
    // Changes waiting for a review, from the home page.
    review: async (page) => {
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await page.locator('a[href*="/changes/"]').first().click();
        await page.waitForURL(/\/changes\/[0-9a-f-]{36}$/);
        await page.waitForLoadState('networkidle');
    },
    people: '/admin/users',
    'role-editor': '/admin/roles/editor',
    settings: '/admin/settings/site',
    extensions: '/admin/extensions',
    components: '/admin/ui',
};

test.beforeEach(async ({ page }) => signIn(page));

for (const [name, path] of Object.entries(screens)) {
    test(name, async ({ page }) => {
        typeof path === 'function' ? await path(page) : await page.goto(path, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);

        await expect(page).toHaveScreenshot(`${name}.png`, {
            fullPage: true,
            // Home says it in words: the time of day, the week and what waits since when.
            mask: [page.locator('time'), page.locator('.home-head, .home-week, .latest, .inbox__text, .inbox__meta, .health')],
            stylePath: new URL('./screenshot.css', import.meta.url).pathname,
        });
    });
}
