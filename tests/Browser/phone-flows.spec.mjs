/*
 * The phone's own flows (mockups 11 and 12): writing a quick post, and approving changes, without the
 * top bar and the menu.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

test.beforeEach(async ({ page }) => signIn(page));

test('a quick post: writing, then the rest', async ({ page }) => {
    await page.goto('/admin/content/post/new', { waitUntil: 'networkidle' });
    await expect(page.locator('.q-rail')).toBeHidden();

    await page.locator('#title').fill('Match day 1: what to know');
    await page.locator('.cm-content').tap();
    await page.keyboard.type('Gates open at 16:30.');
    await expect(page.locator('.markdown-toolbar')).toBeVisible();
    await page.locator('.markdown-toolbar button[aria-label^="Bold"]').tap();
    await expect(page.locator('.cm-content')).toBeFocused();

    await page.locator('.editor-bar__step').tap();
    await expect(page.locator('.editor__main')).toBeHidden();
    await expect(page.locator('.editor-bar__step')).toHaveText('Back to the text');
    await page.getByRole('button', { name: 'Save', exact: true }).tap();
    await page.waitForURL(/\/admin\/content\/post\/[0-9a-f-]{36}$/);
    await expect(page.locator('#title')).toHaveValue('Match day 1: what to know');
});

test('approving changes, the verdict in reach of the thumb', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.locator('.inbox a[href*="/changes/"]', { hasText: 'Review changes' }).first().tap();
    await page.waitForURL(/\/changes\/[0-9a-f-]{36}$/);
    const approve = page.getByRole('button', { name: 'Approve', exact: true });
    await expect(approve).toBeInViewport();
    await approve.tap();
    await expect(page.locator('.q-notice--success')).toBeVisible();
});
