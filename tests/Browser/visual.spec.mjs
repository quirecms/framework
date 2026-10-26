/*
 * The admin's screens, compared with the approved screenshots in screenshots/. Times and what changes
 * with them (the latest activity) are masked: the demo site is dated relative to when it was made.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

const screens = {
    home: '/admin',
    posts: '/admin/content/post',
    'new-post': '/admin/content/post/new',
    people: '/admin/users',
    'role-editor': '/admin/roles/editor',
    settings: '/admin/settings/site',
    extensions: '/admin/extensions',
    components: '/admin/ui',
};

test.beforeEach(async ({ page }) => signIn(page));

for (const [name, path] of Object.entries(screens)) {
    test(name, async ({ page }) => {
        await page.goto(path, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);

        await expect(page).toHaveScreenshot(`${name}.png`, {
            fullPage: true,
            mask: [page.locator('time'), page.locator('.widgets .q-card:has(a[href*="/admin/audit"])')],
        });
    });
}
