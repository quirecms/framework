/*
 * Moving between the admin and the site: each keeps its own stylesheets and scripts. Turbo drives only
 * the admin's pages (its "turbo-root"), so it never merges the site's <head> into the admin's.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

const stylesheets = (page) => page.locator('link[rel=stylesheet]').evaluateAll((links) => links.map((link) => new URL(link.href).pathname));

test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'light', 'The same in every look');
    await signIn(page);
});

test('going to the site and back keeps the admin as it was', async ({ page }) => {
    await page.goto('/admin/content/post', { waitUntil: 'networkidle' });
    const admin = await stylesheets(page);

    await page.getByRole('link', { name: /view site/ }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/admin'));
    expect((await stylesheets(page)).filter((path) => path.includes('quire-admin/')), 'the site has none of the admin\'s styles').toEqual([]);

    await page.goBack();
    await page.waitForURL(/\/admin\/content\/post$/);
    await expect(page.locator('.q-rail')).toBeVisible();
    expect(await stylesheets(page)).toEqual(admin);
});
