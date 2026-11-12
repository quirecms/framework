/*
 * Two people editing one published item at once, each in a browser of their own, as the audit found the
 * lost update (C-1, D-41): what one publishes is never taken back by the other's older tab. Changes the
 * demo site, so it runs after the flows (playwright.config.mjs).
 */
import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'default' });

const LINES = ['Line one.', 'Line two.', 'Line three.', 'Line four.'];

async function signInAs(page, username) {
    await page.goto('/login');
    await page.fill('#username', username);
    await page.fill('#password', 'correct-horse-42');
    await Promise.all([page.waitForURL(/\/(admin)?$/), page.click('form:has(#password) button[type=submit]')]);
}

// The demo site asks for approval; publishing changes right away is what a fresh site does
async function approvalOn(page, on) {
    await page.goto('/admin/settings/content', { waitUntil: 'networkidle' });
    await page.locator('#setting-quire-content-changes_need_approval').setChecked(on);
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.locator('.q-notice--success')).toBeVisible();
}

async function newPublishedPost(page, title) {
    await page.goto('/admin/content/post/new', { waitUntil: 'networkidle' });
    await page.fill('#title', title);
    await page.locator('.cm-content').click();
    await page.keyboard.type(LINES.join('\n\n'));
    await page.locator('.editor-bar__publish').click();
    await page.locator('#publish-popover button[name=intent][value="transition:publish"]').click();
    await page.waitForURL(/\/admin\/content\/post\/[0-9a-f-]{36}$/);

    return page.url();
}

// Types at the end of a line of the text, as an editor does
async function typeAtTheEndOf(page, line, text) {
    await page.locator('.cm-line', { hasText: line }).click();
    await page.keyboard.press('End');
    await page.keyboard.type(text);
}

async function publishChanges(page) {
    await page.locator('.editor-bar__publish').click();
    await page.locator('#publish-popover button[name=intent][value="publish_changes"]').click();
    await expect(page.locator('.q-notice--success')).toContainText('Your changes are published.');
}

test('what one editor publishes, another editor\'s older tab keeps', async ({ browser }) => {
    const alice = await (await browser.newContext()).newPage();
    const bob = await (await browser.newContext()).newPage();
    await signInAs(alice, 'noor');
    await signInAs(bob, 'grace');
    await approvalOn(alice, false);

    try {
        const editor = await newPublishedPost(alice, 'Two editors at once');

        // Bob opens it and leaves the tab; Alice corrects line two and publishes.
        await bob.goto(editor, { waitUntil: 'networkidle' });
        await expect(bob.locator('.editor-bar__headline')).toContainText('what you see is what visitors see');
        await alice.goto(editor, { waitUntil: 'networkidle' });
        await typeAtTheEndOf(alice, 'Line two.', ' Corrected by Alice.');
        await expect(alice.locator('[data-content-editor-target="status"]')).toHaveText(/^saved /, { timeout: 10_000 });
        await publishChanges(alice);

        // Bob types on line four in his old tab: its autosave tells him, before he publishes.
        await typeAtTheEndOf(bob, 'Line four.', ' Added by Bob.');
        await expect(bob.locator('[data-content-editor-target="status"]')).toContainText('Someone published changes since you opened this', { timeout: 10_000 });
        await bob.reload({ waitUntil: 'networkidle' });
        await expect(bob.locator('.q-notice[role=status]')).toContainText('They\'re kept');
        await publishChanges(bob);

        // Visitors see both.
        await bob.locator('a', { hasText: 'View on the site' }).click();
        await expect(bob.locator('main')).toContainText('Line two. Corrected by Alice.');
        await expect(bob.locator('main')).toContainText('Line four. Added by Bob.');
    } finally {
        await approvalOn(alice, true);
    }
});

test('two tabs of one editor never replace each other\'s changes unknowingly', async ({ browser }) => {
    const context = await browser.newContext();
    const first = await context.newPage();
    await signInAs(first, 'noor');
    await approvalOn(first, false);

    try {
        const editor = await newPublishedPost(first, 'One editor, two tabs');
        const second = await context.newPage();
        await first.goto(editor, { waitUntil: 'networkidle' });
        await second.goto(editor, { waitUntil: 'networkidle' });

        await typeAtTheEndOf(first, 'Line one.', ' From the first tab.');
        await expect(first.locator('[data-content-editor-target="status"]')).toHaveText(/^saved /, { timeout: 10_000 });

        // The second tab was opened before those changes: its autosave doesn't replace them (H-9).
        await typeAtTheEndOf(second, 'Line three.', ' From the second tab.');
        await expect(second.locator('[data-content-editor-target="status"]')).toContainText('saved elsewhere meanwhile', { timeout: 10_000 });

        await first.reload({ waitUntil: 'networkidle' });
        await expect(first.locator('.cm-content')).toContainText('From the first tab.');
        await expect(first.locator('.cm-content')).not.toContainText('From the second tab.');
    } finally {
        await approvalOn(first, true);
    }
});
