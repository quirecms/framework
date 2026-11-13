/*
 * The screenshot comparison can fail: changes it must catch, made to the Extensions screen in the browser,
 * each of which makes it fail (measured: a hovered button about 230 pixels, a number one less about 430, a
 * card missing about 15,000; the limit is 50). A tolerance that lets one through (as 1% of the page once let a whole new
 * card and a whole new tab through) fails this file. Never run while updating screenshots: it would write
 * them.
 */
import { test, expect } from '@playwright/test';
import { signIn } from './sign-in.mjs';

const changes = {
    'a card missing': async (page) => page.evaluate(() => document.querySelectorAll('.extension-card')[1]?.remove()),
    'a number one less': async (page) => page.evaluate(() => {
        // "Adds 6 preferences · 28 admin pages": 28 becomes 27.
        const text = [...document.querySelector('.extension-card__adds').childNodes].find((node) => node.nodeType === Node.TEXT_NODE && /\d+ admin page/.test(node.textContent));
        text.textContent = text.textContent.replace(/(\d+)( admin page)/, (all, count, rest) => `${Number(count) - 1}${rest}`);
    }),
    'a button left hovered': async (page) => page.locator('.extension-card .q-btn').first().hover(),
};

test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.config.updateSnapshots !== 'none' && testInfo.config.updateSnapshots !== 'missing', 'It would write the screenshots it changes.');
    await signIn(page);
});

for (const [name, change] of Object.entries(changes)) {
    test(`the comparison catches ${name}`, async ({ page }, testInfo) => {
        // Touch screens show no hover.
        test.skip(name.includes('hovered') && testInfo.project.use.hasTouch === true, 'No hover on a touch screen.');
        await page.goto('/admin/extensions', { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await page.mouse.move(0, 0);
        await change(page);

        let caught = null;

        try {
            await expect(page).toHaveScreenshot('extensions.png', {
                fullPage: true,
                mask: [page.locator('time'), page.locator('.home-head, .home-week, .latest, .inbox__text, .inbox__meta, .health, .content-list__group')],
                stylePath: new URL('./screenshot.css', import.meta.url).pathname,
                timeout: 3000,
            });
        } catch (error) {
            caught = error;
        }

        expect(caught, `${name} passed as the approved screenshot`).not.toBeNull();
        expect(String(caught?.message)).toMatch(/pixels? .*(are|is) different/);
    });
}
