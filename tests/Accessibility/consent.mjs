/*
 * Checks quire/privacy's banner, settings dialog and consent loader in a browser, on a running site
 * with quire-privacy enabled and two services to ask about (consent-site/, as CI sets it up):
 * what visitors see and can do, what's kept, what the keyboard reaches, and axe-core (WCAG 2.2 AA) on
 * the banner and on the open dialog, light and dark, at desktop width and at 320px. Exits with 1 when
 * something fails.
 *
 *     node consent.mjs https://quire.localhost
 *
 * QUIRE_CHECK_SERVER works as for check.mjs. Outside production the loader is in test mode: it asks,
 * keeps the choice and sends events, and starts no service's script; this is checked too.
 *
 * With QUIRE_CHECK_SENDS=1, for a site with "quire-privacy.send_in_test" on, it checks only what the
 * loader is for: a service's script isn't even requested before consent or after a refusal, starts
 * after accepting, and stops when the visitor withdraws.
 */
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const base = process.argv[2] ?? 'https://quire.localhost';
const server = process.env.QUIRE_CHECK_SERVER;
const browser = await chromium.launch({ args: server ? [`--host-resolver-rules=MAP ${new URL(base).hostname} ${server}`] : [] });
let failures = 0;

async function check(name, run) {
    try {
        await run();
        console.log(`✓ ${name}`);
    } catch (error) {
        failures++;
        console.log(`✗ ${name}\n    ${String(error?.message ?? error).split('\n').join('\n    ')}`);
    }
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

// Google's hosts never see CI: gtag.js is a stand-in that sets the cookies Google's would and counts
// its loads, and hits are answered empty. What would be sent is read from window.dataLayer.
const GOOGLE = /^https:\/\/([a-z0-9-]+\.)*(googletagmanager\.com|google-analytics\.com|analytics\.google\.com)\//;
const GTAG = `document.cookie = '_ga=GA1.1.1; Path=/; Domain=quire.localhost'; document.cookie = '_ga_CITEST123=GS1.1.1; Path=/; Domain=quire.localhost'; window.ciGtag = (window.ciGtag ?? 0) + 1;`;

async function fresh(options = {}) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, ...options });
    await context.route(GOOGLE, (route) => route.request().url().includes('/gtag/js')
        ? route.fulfill({ status: 200, contentType: 'text/javascript', body: GTAG })
        : route.fulfill({ status: 204 }));
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(String(error)));
    page.on('console', (message) => message.type() === 'error' && errors.push(message.text()));

    return { context, page, errors };
}

async function choice(context) {
    const cookie = (await context.cookies()).find((c) => c.name === 'quire_consent');

    return cookie ? JSON.parse(Buffer.from(cookie.value.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString()) : null;
}

async function axe(page, label) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations.length === 0, `${label}: ${results.violations.map((v) => `${v.id} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`).join('; ')}`);
}

const script = (request) => request.url().includes('/assets/ci-consent/start');

async function requests(page) {
    const seen = [];
    page.on('request', (request) => script(request) && seen.push(request.url()));

    return seen;
}

if (process.env.QUIRE_CHECK_SENDS === '1') {
    await check('a service\'s script isn\'t requested before consent or after a refusal, starts after accepting, and stops when withdrawn', async () => {
        const { context, page, errors } = await fresh();
        const seen = await requests(page);
        await page.goto(base + '/', { waitUntil: 'networkidle' });
        expect(seen.length === 0, `Requested before consent: ${seen.join(', ')}`);
        await page.locator('[data-consent-banner] [data-consent-reject]').click();
        await page.reload({ waitUntil: 'networkidle' });
        expect(seen.length === 0, `Requested after a refusal: ${seen.join(', ')}`);

        await page.locator('.consent__opener').click();
        await page.locator('[data-consent-dialog] [data-consent-accept]').click();
        await page.waitForFunction(() => document.documentElement.dataset.ciVideo === 'started', null, { timeout: 5000 });
        expect(seen.length === 1, `Requested ${seen.length} times after accepting.`);
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.documentElement.dataset.ciVideo === 'started', null, { timeout: 5000 });

        await page.locator('.consent__opener').click();
        await page.locator('[data-consent-dialog] [data-consent-reject]').click();
        await page.waitForFunction(() => document.documentElement.dataset.ciVideo === 'stopped', null, { timeout: 5000 });
        const before = seen.length;
        await page.reload({ waitUntil: 'networkidle' });
        expect(seen.length === before && (await page.evaluate(() => document.documentElement.dataset.ciVideo)) === undefined, 'Started again after withdrawing.');
        expect(errors.length === 0, errors.join('; '));
        await context.close();
    });

    await check('nothing of an embed is requested before consent, and markup escaped in <noscript> never gets out of its placeholder', async () => {
        const { context, page } = await fresh();
        const seen = [];
        page.on('request', (request) => (request.url().includes('/ci-consent/embed') || request.url().includes('/ci-leak') || request.frame() !== page.mainFrame()) && seen.push(request.url()));
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        expect(seen.length === 0, `Requested before consent: ${seen.join(', ')}`);
        expect(await page.locator('main img, main iframe').count() === 0, 'An element of an embed is in the page before consent.');
        const probe = page.locator('main [data-consent-embed=""]');
        expect(await probe.count() === 1 && await probe.locator('[data-consent-embed-allow]').count() === 0, 'The probe isn\'t held with "Load it" only.');
        await page.locator('[data-consent-banner] [data-consent-reject]').click();
        await probe.locator('[data-consent-embed-load]').click();
        await page.waitForTimeout(500);
        expect(!seen.some((url) => url.includes('/ci-leak')), 'The escaped image loaded.');
        expect(await page.locator('main iframe[title="Probe"]').count() === 1, 'The probe\'s frame didn\'t load with "Load it".');
        await context.close();
    });

    await check('"Load it" loads one embed this time, runs its scripts as the page would (an inline one stays blocked), and keeps nothing', async () => {
        const { context, page } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.locator('[data-consent-banner] [data-consent-reject]').click();
        const kept = JSON.stringify((await choice(context)).s);
        expect(await page.locator('main iframe').count() === 0, 'An embed loaded before the click.');
        await page.locator('[data-consent-embed="ci-video"] [data-consent-embed-load]').click();
        await page.waitForFunction(() => document.documentElement.dataset.ciEmbed === '1', null, { timeout: 5000 });
        expect(await page.locator('main iframe[data-ci-frame]').count() === 1, 'No frame after "Load it".');
        expect(await page.evaluate(() => document.documentElement.dataset.ciInline) === undefined, 'An inline script of the embed ran: the policy must block it, as on the page.');
        expect(JSON.stringify((await choice(context)).s) === kept, 'Loading once changed the visitor\'s choice.');
        await page.reload({ waitUntil: 'networkidle' });
        expect(await page.locator('main [data-consent-embed="ci-video"]').count() === 1 && await page.locator('main iframe').count() === 0, 'Loaded again without a click.');
        await context.close();
    });

    await check('"Always allow" keeps a yes for the service: its embeds load at once from then on, and go back to their placeholder when withdrawn', async () => {
        const { context, page, errors } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.locator('[data-consent-banner] [data-consent-reject]').click();
        await page.locator('[data-consent-embed="ci-video"] [data-consent-embed-allow]').click();
        await page.waitForFunction(() => document.documentElement.dataset.ciEmbed === '1', null, { timeout: 5000 });
        expect(/^[0-9a-f]{12}$/.test((await choice(context)).s['ci-video']), 'No "yes" kept for the service.');
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.documentElement.dataset.ciEmbed === '1', null, { timeout: 5000 });
        expect(await page.locator('main [data-consent-embed="ci-video"]').count() === 0, 'The placeholder stays after allowing.');
        await page.locator('.consent__opener').click();
        await page.locator('[data-consent-dialog] [data-consent-service="ci-video"]').uncheck();
        await page.locator('[data-consent-dialog] [data-consent-save]').click();
        expect(await page.locator('main [data-consent-embed="ci-video"]').count() === 1 && await page.locator('main iframe[data-ci-frame]').count() === 0, 'Still loaded after withdrawing.');
        // The embed's inline script is refused by design (the check above).
        const unexpected = errors.filter((error) => !error.startsWith('Refused to execute inline script'));
        expect(unexpected.length === 0, unexpected.join('; '));
        await context.close();
    });

    await check('allowing the service anywhere (window.quire.consent, the quire:consent event) loads its embeds', async () => {
        const { context, page } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.evaluate(() => window.quire.consent.set({'ci-video': true}));
        await page.waitForFunction(() => document.documentElement.dataset.ciEmbed === '1', null, { timeout: 5000 });
        expect(await page.locator('main iframe[data-ci-frame]').count() === 1, 'No frame after allowing.');
        await context.close();
    });

    // quire/analytics (Google Analytics 4): what gtag would send, read from window.dataLayer.
    const layer = (page) => page.evaluate(() => (window.dataLayer ?? []).map((entry) => JSON.parse(JSON.stringify(Array.from(entry)))));
    const googleRequests = (page) => {
        const seen = [];
        page.on('request', (request) => GOOGLE.test(request.url()) && seen.push(`${request.url()} (from ${request.frame().url()})`));

        return seen;
    };

    await check('Google Analytics: nothing before consent; then the page\'s address and referrer go without tokens, only campaigns kept', async () => {
        const { context, page } = await fresh();
        const seen = googleRequests(page);
        await page.goto(base + '/embed?token=secret&email=a%40b.example&utm_source=ci#top', { waitUntil: 'networkidle', referer: base + '/reset-password?token=abc&utm_medium=mail' });
        expect(seen.length === 0, `Google was asked before consent: ${seen.join(', ')}`);
        await page.locator('[data-consent-banner] [data-consent-accept]').click();
        await page.waitForFunction(() => window.ciGtag === 1, null, { timeout: 5000 });
        const config = (await layer(page)).find((entry) => entry[0] === 'config');
        expect(config?.[1] === 'G-CITEST123', `No config: ${JSON.stringify(await layer(page))}`);
        expect(config[2].page_location === base + '/embed?utm_source=ci', `page_location: ${config[2].page_location}`);
        expect(config[2].page_referrer === base + '/reset-password?utm_medium=mail', `page_referrer: ${config[2].page_referrer}`);
        expect(config[2].allow_google_signals === false && config[2].allow_ad_personalization_signals === false, 'Signals or ad personalisation on.');
        // The video's frame is a page of the site (/about): it doesn't count a second page view.
        const frame = await (await page.locator('main iframe[data-ci-frame]').elementHandle())?.contentFrame();
        await frame?.waitForURL(/\/about$/);
        await frame?.waitForLoadState('networkidle');
        // Its loader has run (consent is the page's), and Google Analytics didn't start.
        await frame?.waitForFunction(() => window.quire?.consent?.allows('google-analytics') === true, null, { timeout: 5000 });
        expect(frame && await frame.evaluate(() => window.ciGtag === undefined && (window.dataLayer ?? []).length === 0), 'A page in a frame of the site started Google Analytics.');
        const consent = (await layer(page)).find((entry) => entry[0] === 'consent');
        expect(consent?.[2].ad_storage === 'denied' && consent[2].ad_user_data === 'denied' && consent[2].ad_personalization === 'denied', `Advertising not denied: ${JSON.stringify(consent)}`);
        await context.close();
    });

    await check('Google Analytics: events marked in templates go only after consent, within GA4\'s limits; one before consent is dropped, not kept', async () => {
        const { context, page } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.locator('[data-consent-banner] [data-consent-reject]').click();
        await page.locator('[data-ci-event]').click();
        await page.locator('.consent__opener').click();
        await page.locator('[data-consent-dialog] [data-consent-service="google-analytics"]').check();
        await page.locator('[data-consent-dialog] [data-consent-save]').click();
        await page.waitForFunction(() => window.ciGtag === 1, null, { timeout: 5000 });
        expect(!(await layer(page)).some((entry) => entry[0] === 'event'), 'An event from before consent was sent.');
        await page.locator('[data-ci-event]').click();
        await page.locator('[data-ci-bad-event]').click();
        const events = (await layer(page)).filter((entry) => entry[0] === 'event');
        expect(events.length === 1 && events[0][1] === 'ci_click', `Events: ${JSON.stringify(events)}`);
        const parameters = events[0][2];
        expect(Object.keys(parameters).length === 25, `${Object.keys(parameters).length} parameters, not GA4's 25.`);
        expect(parameters.link_text?.length === 100, `A value of ${parameters.link_text?.length} characters, not GA4's 100.`);
        await context.close();
    });

    await check('Google Analytics: withdrawing removes _ga and _ga_* on the site\'s domain, and nothing more is sent', async () => {
        const { context, page } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.locator('[data-consent-banner] [data-consent-accept]').click();
        await page.waitForFunction(() => window.ciGtag === 1, null, { timeout: 5000 });
        expect((await page.evaluate(() => document.cookie)).includes('_ga='), 'The stand-in set no cookie.');
        await page.locator('.consent__opener').click();
        await page.locator('[data-consent-dialog] [data-consent-service="google-analytics"]').uncheck();
        await page.locator('[data-consent-dialog] [data-consent-save]').click();
        const cookies = await page.evaluate(() => document.cookie);
        expect(!/(^|; )_ga/.test(cookies), `Cookies left: ${cookies}`);
        expect(await page.evaluate(() => window['ga-disable-G-CITEST123']) === true, 'gtag isn\'t told to stop.');
        const before = (await layer(page)).filter((entry) => entry[0] === 'event').length;
        await page.locator('[data-ci-event]').click();
        expect((await layer(page)).filter((entry) => entry[0] === 'event').length === before, 'An event was sent after withdrawing.');
        await context.close();
    });

    await check('Google Analytics: pages with password forms have no loader, so nothing is sent there, consent or not', async () => {
        const { context, page } = await fresh();
        await page.goto(base + '/embed', { waitUntil: 'networkidle' });
        await page.locator('[data-consent-banner] [data-consent-accept]').click();
        await page.waitForFunction(() => window.ciGtag === 1, null, { timeout: 5000 });
        const seen = googleRequests(page);
        await page.goto(base + '/login', { waitUntil: 'networkidle' });
        expect(await page.locator('#quire-consent-config').count() === 0 && seen.length === 0, `On the sign-in page: ${seen.join(', ')}`);
        await context.close();
    });

    await browser.close();
    console.log(failures === 0 ? 'Services start only with consent.' : `${failures} check(s) failed.`);
    process.exit(failures === 0 ? 0 : 1);
}

await check('in test mode an embed waits in its placeholder, which says what loading does, and "Load it" loads nothing', async () => {
    const { context, page, errors } = await fresh();
    await page.goto(base + '/embed', { waitUntil: 'networkidle' });
    await page.locator('[data-consent-banner] [data-consent-accept]').click();
    const box = page.locator('main [data-consent-embed="ci-video"]');
    expect(await box.count() === 1, 'No placeholder.');
    expect((await box.innerText()).includes('Loading it sends CI Video Ltd your address (IP)'), 'The placeholder doesn\'t say what loading does.');
    expect(await box.locator('[data-consent-embed-test]').isVisible(), 'It doesn\'t say nothing loads here.');
    await box.locator('[data-consent-embed-load]').click();
    await page.waitForTimeout(500);
    expect(await page.locator('main iframe').count() === 0 && await page.evaluate(() => document.documentElement.dataset.ciEmbed) === undefined, 'Loaded in test mode.');
    await axe(page, 'the placeholder');
    expect(errors.length === 0, errors.join('; '));
    await context.close();
});

await check('in test mode a service\'s script isn\'t even requested, accepted or not', async () => {
    const { context, page } = await fresh();
    const seen = await requests(page);
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-consent-banner] [data-consent-accept]').click();
    await page.reload({ waitUntil: 'networkidle' });
    expect(seen.length === 0 && (await page.evaluate(() => window.quire.consent.allows('ci-video'))), `Requested in test mode: ${seen.join(', ')}`);
    await context.close();
});

await check('a first visit shows the banner, keeps room for it, and the page has no errors', async () => {
    const { context, page, errors } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    const banner = page.locator('[data-consent-banner]');
    expect(await banner.isVisible(), 'The banner isn\'t shown.');
    const [height, padding] = await page.evaluate(() => [document.querySelector('[data-consent-banner]').offsetHeight, parseFloat(getComputedStyle(document.body).paddingBottom)]);
    expect(Math.abs(height - padding) <= 1, `The page keeps ${padding}px for a banner of ${height}px.`);
    expect(await page.locator('.consent__opener').isVisible(), 'The "Cookie settings" opener isn\'t shown.');
    const buttons = await banner.locator('.consent__btn').evaluateAll((elements) => elements.map((e) => { const s = getComputedStyle(e); return [e.offsetWidth, s.backgroundColor, s.color, s.fontWeight, s.borderTopWidth].join(' '); }));
    expect(buttons.length === 2 && buttons[0] === buttons[1], `Accepting and rejecting don't look the same: ${buttons.join(' | ')}`);
    expect(errors.length === 0, errors.join('; '));
    await context.close();
});

await check('the keyboard reaches the skip link, then the banner', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => document.activeElement?.classList.contains('skip-link')), 'The first Tab doesn\'t reach the skip link.');
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => document.activeElement?.closest('[data-consent-banner]') !== null), 'The second Tab doesn\'t reach the banner.');
    await context.close();
});

await check('"Reject all" keeps a refusal of every service, hides the banner, moves focus and says so', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-consent-banner] [data-consent-reject]').click();
    expect(!(await page.locator('[data-consent-banner]').isVisible()), 'The banner stays.');
    const kept = await choice(context);
    expect(kept && kept.s['ci-video'] === false && kept.s['ci-fonts'] === false && /^[0-9a-f]{32}$/.test(kept.id), `Kept: ${JSON.stringify(kept)}`);
    expect(await page.evaluate(() => document.activeElement?.classList.contains('consent__opener')), 'Focus isn\'t on "Cookie settings".');
    await page.waitForFunction(() => document.querySelector('[data-consent-status]')?.textContent !== '');
    await page.reload({ waitUntil: 'networkidle' });
    expect(!(await page.locator('[data-consent-banner]').isVisible()), 'Asked again after choosing.');
    await context.close();
});

await check('each choice is logged as proof, by the ID the dialog shows', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    const logged = page.waitForResponse((response) => response.url().endsWith('/_quire/privacy/consent') && response.request().method() === 'POST');
    await page.locator('[data-consent-banner] [data-consent-accept]').click();
    const response = await logged;
    const sent = response.request().postDataJSON();
    const kept = await choice(context);
    expect(response.status() === 204, `The log answered ${response.status()}.`);
    expect(sent.id === kept.id && JSON.stringify(sent.s) === JSON.stringify(kept.s), `Logged ${JSON.stringify(sent)}, kept ${JSON.stringify(kept)}`);
    await page.locator('.consent__opener').click();
    expect((await page.locator('[data-consent-dialog] [data-consent-id] code').textContent()) === kept.id, 'The dialog doesn\'t show the choice\'s ID.');
    await context.close();
});

await check('"Cookie settings" reopens the choice; saving one service keeps it, and focus comes back', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-consent-banner] [data-consent-reject]').click();
    const opener = page.locator('.consent__opener');
    await opener.click();
    const dialog = page.locator('[data-consent-dialog]');
    expect(await dialog.isVisible(), 'The dialog didn\'t open.');
    expect(await page.evaluate(() => document.querySelector('[data-consent-dialog]').contains(document.activeElement)), 'Focus isn\'t in the dialog.');
    expect(!(await dialog.locator('[data-consent-service="ci-video"]').isChecked()), 'The refused service is ticked.');
    await dialog.locator('[data-consent-service="ci-video"]').check();
    expect(await dialog.locator('[data-consent-category="marketing"]').isChecked(), 'Its category doesn\'t follow its only service.');
    await dialog.locator('[data-consent-save]').click();
    expect(!(await dialog.isVisible()), 'The dialog stays open.');
    expect(await page.evaluate(() => document.activeElement?.classList.contains('consent__opener')), 'Focus didn\'t come back to "Cookie settings".');
    const kept = await choice(context);
    expect(/^[0-9a-f]{12}$/.test(kept.s['ci-video']) && kept.s['ci-fonts'] === false, `Kept: ${JSON.stringify(kept.s)}`);
    expect(await page.evaluate(() => window.quire.consent.allows('ci-video') && window.quire.consent.allows('marketing') && !window.quire.consent.allows('ci-fonts')), 'window.quire.consent disagrees.');
    await context.close();
});

await check('withdrawing removes the service\'s cookies and says it\'s off', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-consent-banner] [data-consent-accept]').click();
    await page.evaluate(() => { document.cookie = 'ci_video_id=1; Path=/'; });
    const withdrawn = page.evaluate(() => new Promise((resolve) => document.addEventListener('quire:consent', (event) => resolve(event.detail), { once: true })));
    await page.locator('.consent__opener').click();
    await page.locator('[data-consent-dialog] [data-consent-reject]').click();
    const detail = await withdrawn;
    expect(detail.withdrawn.includes('ci-video'), `The event says: ${JSON.stringify(detail)}`);
    expect(!(await context.cookies()).some((c) => c.name === 'ci_video_id'), 'Its cookie stays.');
    await page.waitForFunction(() => document.querySelector('[data-consent-status]')?.textContent.includes('CI Video'));
    await context.close();
});

await check('a service added or changed since the visitor chose is asked about by name, and other answers stay', async () => {
    const { context, page } = await fresh();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    const revisions = await page.evaluate(() => Object.fromEntries(JSON.parse(document.getElementById('quire-consent-config').textContent).services.map((s) => [s.id, s.revision])));
    const host = new URL(base).hostname;
    // A "yes" to fonts as they are, and a "yes" to the video before it changed.
    const old = Buffer.from(JSON.stringify({ v: 1, id: 'aaaabbbbccccddddaaaabbbbccccdddd', t: Math.floor(Date.now() / 1000), r: 'old', s: { 'ci-fonts': revisions['ci-fonts'], 'ci-video': '000000000000' } })).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    await context.addCookies([{ name: 'quire_consent', value: old, domain: host, path: '/' }]);
    await page.reload({ waitUntil: 'networkidle' });
    expect(await page.locator('[data-consent-banner]').isVisible(), 'Not asked about the changed service.');
    expect(await page.evaluate(() => !window.quire.consent.allows('ci-video') && window.quire.consent.allows('ci-fonts')), 'The old "yes" still counts for the changed service, or the other answer is lost.');
    expect((await page.locator('[data-consent-new]').innerText()).includes('CI Video'), 'The changed service isn\'t named.');
    await page.locator('[data-consent-banner] [data-consent-reject]').click();
    const kept = await choice(context);
    expect(kept.s['ci-fonts'] === revisions['ci-fonts'] && kept.s['ci-video'] === false && kept.id === 'aaaabbbbccccddddaaaabbbbccccdddd', `Kept: ${JSON.stringify(kept)}`);
    await context.close();
});

await check('Global Privacy Control: no banner, everything off, and the dialog says why', async () => {
    const { context, page } = await fresh();
    await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true }));
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    expect(!(await page.locator('[data-consent-banner]').isVisible()), 'The banner is shown.');
    await page.locator('.consent__opener').click();
    expect(await page.locator('[data-consent-signal]').isVisible(), 'The dialog doesn\'t say why.');
    expect(await page.locator('[data-consent-service="ci-video"]').isDisabled(), 'The switches can be changed.');
    expect(!(await page.locator('[data-consent-dialog] [data-consent-accept]').isVisible()), '"Accept all" is offered.');
    expect(await page.evaluate(() => !window.quire.consent.allows('ci-video')), 'A service is allowed.');
    await context.close();
});

await check('without JavaScript there\'s no banner and no dead button', async () => {
    const { context, page } = await fresh({ javaScriptEnabled: false });
    await page.goto(base + '/');
    expect(!(await page.locator('[data-consent-banner]').isVisible()) && !(await page.locator('.consent__opener').isVisible()), 'Something of the banner shows.');
    await context.close();
});

for (const colorScheme of ['light', 'dark']) {
    for (const width of [1280, 320]) {
        await check(`axe: the banner and the open dialog (${colorScheme}, ${width}px)`, async () => {
            const { context, page } = await fresh({ colorScheme, viewport: { width, height: 900 } });
            await page.goto(base + '/', { waitUntil: 'networkidle' });
            await axe(page, 'with the banner');
            const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
            expect(!wide, 'The page is wider than the screen.');
            await page.locator('[data-consent-banner] [data-consent-open]').first().click();
            await axe(page, 'with the dialog open');
            await context.close();
        });
    }
}

await browser.close();
console.log(failures === 0 ? 'The banner works.' : `${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
