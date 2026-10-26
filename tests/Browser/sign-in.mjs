/** Signs in as the demo site's admin (see bin/console quire:demo:seed). */
export async function signIn(page) {
    await page.goto('/login');
    await page.fill('#username', 'ada');
    await page.fill('#password', 'correct-horse-42');
    await Promise.all([page.waitForURL(/\/(admin)?$/), page.click('form button[type=submit]')]);
}
