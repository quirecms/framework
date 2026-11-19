/*
 * Quire's account pages in a browser, signed out: what the PHP tests can't see, their scripts.
 */
import { test, expect } from '@playwright/test';

test('a password can be shown, and is a password field again before the form is sent', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'networkidle' });
    const field = page.locator('#password');
    const toggle = page.locator('button[data-quire-password-toggle]');

    // Its script reveals it
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    // Both fields, or the browser's own check of the required username stops the form before it's sent
    await page.locator('#username').fill('someone');
    await field.fill('correct horse');

    await toggle.click();
    await expect(field).toHaveAttribute('type', 'text');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(field).toBeFocused();
    // As text, never sent to a browser's spell-checking service
    expect(await field.evaluate((input) => input.spellcheck)).toBe(false);

    await toggle.click();
    await expect(field).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');

    // Shown, then sent: the browser gets a password field, never plain text it could keep
    await toggle.click();
    await page.evaluate(() => {
        const form = document.querySelector('#password').form;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            window.typeWhenSent = document.querySelector('#password').type;
        });
    });
    await page.locator('form button[type=submit]').click();
    expect(await page.evaluate(() => window.typeWhenSent)).toBe('password');
});

test('without JavaScript, the field is a plain password field', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/login');

    await expect(page.locator('button[data-quire-password-toggle]')).toBeHidden();
    await expect(page.locator('#password')).toHaveAttribute('type', 'password');
    await context.close();
});
