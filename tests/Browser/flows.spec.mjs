/*
 * The admin's main flows in a browser, with its scripts (Stimulus controllers): what the PHP tests
 * can't see. They change the demo site (see playwright.config.mjs), each a different part of it.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

// One after the other: some share the site's state, e.g. the list's count.
test.describe.configure({ mode: 'default' });

test.beforeEach(async ({ page }) => signIn(page));

test('the intent bar finds and does, as a plan first', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.keyboard.press('Control+k');
    const bar = page.locator('#intent-dialog-q');
    await expect(bar).toBeFocused();

    await bar.fill('suspend farah');
    await expect(page.locator('.intent-part')).toHaveText(['Suspend', 'Farah Novak']);
    await expect(page.locator('.intent-steps')).toContainText('can’t sign in any more');
    await bar.press('Enter');
    await page.waitForURL(/\/admin\/users\/[0-9a-f-]{36}$/);
    await expect(page.locator('.q-notice--success')).toContainText('Done');

    // And back, by its own words.
    await page.keyboard.press('Control+k');
    await bar.fill('let farah in again');
    await expect(page.locator('.intent-part').first()).toHaveText('Let in again');
    await bar.press('Enter');
    await expect(page.locator('.q-notice--success')).toContainText('can sign in again');

    // Finding: the arrow keys and Enter open what's found.
    await page.keyboard.press('Control+k');
    await bar.fill('referee list');
    await expect(page.locator('#found-content + ul')).toContainText('Referee list');
    await bar.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin\/content\/post\/[0-9a-f-]{36}$/);
});

test('changes to a published item save as they are written, and take notes in the margin', async ({ page }) => {
    await page.goto('/admin/content/post?q=Draw+ceremony', { waitUntil: 'networkidle' });
    await page.locator('.content-list__title', { hasText: 'Draw ceremony' }).click();
    await page.waitForURL(/\/admin\/content\/post\/[0-9a-f-]{36}$/);
    await expect(page.locator('.editor-versions__dot').first()).toBeVisible();

    await page.locator('.cm-content').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(' Streamed live.');
    await expect(page.locator('[data-content-editor-target="status"]')).toHaveText(/^saved /, { timeout: 10_000 });

    // Now they're changes of their own: comment on a word of them.
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('.editor-bar__headline')).toContainText('your changes aren\'t live yet');
    await page.locator('.cm-content').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.press('Control+Shift+ArrowLeft');
    await page.keyboard.press('Control+Shift+ArrowLeft');
    await expect(page.locator('.editor-comment')).toBeVisible();
    await page.locator('#new-comment').fill('Which channel?');
    await page.locator('.editor-comment button').click();
    await expect(page.locator('.editor-note--comment')).toContainText('Which channel?');
});

test('a reviewer approves changes', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.locator('.inbox a[href*="/changes/"]', { hasText: 'Review changes' }).first().click();
    await page.waitForURL(/\/changes\/[0-9a-f-]{36}$/);
    await expect(page.locator('.review-diff')).toBeVisible();
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.locator('.q-notice--success')).toBeVisible();
});

test('a list selects all on the page, all that match, and loads more as it is scrolled', async ({ page }) => {
    await page.goto('/admin/content/post', { waitUntil: 'networkidle' });
    const rows = page.locator('[data-content-list-target="row"]');
    const first = await rows.count();
    const summary = page.locator('[data-content-list-target="summary"]');

    await page.locator('[data-content-list-target="all"]').check();
    await expect(summary).toHaveText(`${first} selected on this page.`);
    await page.locator('[data-content-list-target="matching"]').click();
    await expect(summary).toContainText('that match are selected');
    await page.locator('[data-content-list-target="all"]').uncheck();
    await expect(summary).toHaveText('Select items to act on them.');

    await page.locator('[data-controller="infinite-scroll"]').scrollIntoViewIfNeeded();
    await expect.poll(() => rows.count()).toBeGreaterThan(first);
});

test('the keyboard alone: going places and selecting', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.keyboard.press('g');
    await page.keyboard.press('c');
    await page.waitForURL(/\/admin\/content\/post$/);
    await page.waitForLoadState('networkidle');

    await page.keyboard.press('j');
    await page.keyboard.press('x');
    await expect(page.locator('[data-content-list-target="summary"]')).toHaveText('1 selected on this page.');
});

test('preferences change as they are chosen, and stay', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.keyboard.press('?');
    const dialog = page.locator('#preferences');
    await expect(dialog).toBeVisible();

    await dialog.getByText('Dark', { exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    // Back as it was, for the flows after this one.
    await page.keyboard.press('?');
    await dialog.getByText('Like my system').first().click();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /./);
});

test('menus and the intent bar close as they are expected to, and stay closed', async ({ page }) => {
    await page.goto('/admin/content/post', { waitUntil: 'networkidle' });

    // A row's menu: a click elsewhere closes it, and so does Esc, giving the focus back to its button.
    const menu = page.locator('.content-list__menu').first();
    await menu.locator('summary').click();
    await expect(menu).toHaveAttribute('open', '');
    await page.locator('h1').click();
    await expect(menu).not.toHaveAttribute('open', '');
    await menu.locator('summary').click();
    await page.keyboard.press('Escape');
    await expect(menu).not.toHaveAttribute('open', '');
    await expect(menu.locator('summary')).toBeFocused();

    // The last row's menu isn't cut off by the table's frame: what's at its last entry is that entry.
    const last = page.locator('.content-list__menu').last();
    await last.locator('summary').click();
    const entry = last.locator('.q-menu__panel li').last();
    await expect(entry).toBeVisible();
    const box = await entry.boundingBox();
    expect(await page.evaluate(([x, y]) => document.elementFromPoint(x, y)?.closest('.q-menu__panel') !== null, [box.x + box.width / 2, box.y + box.height / 2])).toBe(true);
    await page.keyboard.press('Escape');

    // The intent bar: Esc and a click beside it close it, and it doesn't open again by itself.
    const dialog = page.locator('.intent-dialog');
    await page.locator('#intent-q').click();
    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await page.waitForTimeout(300);
    await expect(dialog).toBeHidden();

    await page.locator('#intent-q').click();
    await expect(dialog).toBeVisible();
    await page.mouse.click(5, 5);
    await expect(dialog).toBeHidden();
    await page.waitForTimeout(300);
    await expect(dialog).toBeHidden();
});

test('a new post: its slug follows the title, changing it keeps what is written, and bold toggles', async ({ page }) => {
    await page.goto('/admin/content/post/new', { waitUntil: 'networkidle' });
    await page.locator('#title').fill('Spring Draw: All Groups');
    await expect(page.locator('#slug')).toHaveValue('spring-draw-all-groups');
    await expect(page.locator('.editor-sheet__address')).toContainText('spring-draw-all-groups');

    // Bold reads as bold: its marks show only while the cursor is in it, and B again takes it off.
    await page.locator('.cm-content').click();
    await page.keyboard.type('Groups are ');
    await page.keyboard.press('Control+b');
    await page.keyboard.type('drawn');
    const bold = page.getByRole('button', { name: 'Bold' });
    await expect(bold).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('End');
    await page.keyboard.type(' today.');
    await expect(page.locator('.cm-content')).toHaveText('Groups are drawn today.');
    await expect(page.locator('#body')).toHaveValue('Groups are **drawn** today.');
    for (let step = 0; step < 10; step++) {
        await page.keyboard.press('ArrowLeft');
    }
    await expect(bold).toHaveAttribute('aria-pressed', 'true');
    await bold.click();
    await expect(page.locator('#body')).toHaveValue('Groups are drawn today.');

    // "change": to the slug, with the title and text still there.
    await page.locator('.editor-sheet__address a').click();
    await expect(page.locator('#slug')).toBeFocused();
    await expect(page).toHaveURL(/\/new$/);
    await expect(page.locator('#title')).toHaveValue('Spring Draw: All Groups');
    await expect(page.locator('#body')).toHaveValue('Groups are drawn today.');

    // Once written by hand, the slug stays.
    await page.locator('#slug').fill('draw');
    await page.locator('#title').fill('Spring draw, again');
    await page.waitForTimeout(1500);
    await expect(page.locator('#slug')).toHaveValue('draw');
});
