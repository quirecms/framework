// quire/analytics: Google Analytics 4, started by quire/privacy's consent loader only once the visitor
// allows analytics, and stopped when they withdraw (the loader then removes _ga and _ga_*).
//
// gtag.js directly, never Tag Manager, whose container could add any script. Consent Mode v2, basic:
// it runs only with consent, for analytics only; advertising is denied, and Google signals and ad
// personalisation are off. Pages marked as not counted (previews, errors) send nothing.
//
// Events marked in templates, sent only once started (an event before that is dropped, never kept):
//
//     <a href="/contact" data-quire-event="contact_click" data-quire-event-place="footer">
//
// sends "contact_click" with {place: "footer"}; on a form, its submit does. Content written by editors
// can't carry data-* (the sanitiser), so only templates mark events. One file: a module the consent
// loader imports can't import others of its own by a relative path (the asset mapper serves versioned
// paths only).

const KEPT = /^utm_/;
let stopEvents = null;
let started = null;

export function start({config}) {
    if (document.querySelector('meta[name="quire-analytics"][content="off"]') || stopEvents) {
        return;
    }

    const id = String(config.measurement_id ?? '');

    if (!/^G-[A-Z0-9]{4,}$/.test(id)) {
        return;
    }

    // Google's own switch: with it set, gtag sends nothing (developers.google.com/tag-platform/devguides/privacy#turn_off_google_analytics).
    window[`ga-disable-${id}`] = false;
    started = id;
    const keep = new Set(String(config.keep ?? '').split(',').filter(Boolean));
    window.dataLayer = window.dataLayer || [];
    // gtag() pushes its arguments object, as Google's snippet does.
    window.gtag = window.gtag || function gtag() {
        window.dataLayer.push(arguments);
    };

    window.gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted'});
    window.gtag('js', new Date());
    window.gtag('config', id, {
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
        page_location: clean(location.href, keep),
        page_referrer: clean(document.referrer, keep),
    });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    document.head.append(script);

    stopEvents = onEvent((name, parameters) => window.gtag('event', name, parameters));
}

export function stop() {
    stopEvents?.();
    stopEvents = null;
    window.gtag?.('consent', 'update', {analytics_storage: 'denied'});

    // No further hits from what gtag.js measures by itself (scrolls, outbound links), not even
    // cookieless ones.
    if (started) {
        window[`ga-disable-${started}`] = true;
        started = null;
    }
}

// An address without its fragment, and without query parameters other than campaigns (utm_*) and those
// the site keeps: sign-in, reset and other links carry tokens and e-mail addresses.
export function clean(address, keep) {
    if (!address) {
        return '';
    }

    try {
        const url = new URL(address);
        url.hash = '';

        for (const name of [...url.searchParams.keys()]) {
            if (!KEPT.test(name) && !keep.has(name)) {
                url.searchParams.delete(name);
            }
        }

        return url.toString();
    } catch {
        return '';
    }
}

// Google Analytics 4's limits (support.google.com/analytics/answer/9267744): names of 40 characters,
// starting with a letter; values of 100; 25 parameters.
const NAME = /^[A-Za-z][A-Za-z0-9_]{0,39}$/;
const MAX_VALUE = 100;
const MAX_PARAMETERS = 25;

const handlers = new Set();
let listening = false;

// handler(name, parameters); returns what stops it.
function onEvent(handler) {
    handlers.add(handler);
    listen();

    return () => handlers.delete(handler);
}

// The event an element marks, with its parameters; null for none or a name that isn't valid.
function eventOf(element) {
    const name = element.dataset.quireEvent;

    if (typeof name !== 'string' || !NAME.test(name)) {
        return null;
    }

    const parameters = {};

    for (const [key, value] of Object.entries(element.dataset)) {
        if (!key.startsWith('quireEvent') || key === 'quireEvent' || Object.keys(parameters).length >= MAX_PARAMETERS) {
            continue;
        }

        // data-quire-event-link-text → link_text
        const parameter = key.slice('quireEvent'.length).replace(/^[A-Z]/, (letter) => letter.toLowerCase()).replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`);

        if (NAME.test(parameter)) {
            parameters[parameter] = String(value).slice(0, MAX_VALUE);
        }
    }

    return {name, parameters};
}

function listen() {
    if (listening) {
        return;
    }

    listening = true;

    const send = (element) => {
        const event = element ? eventOf(element) : null;

        if (event) {
            for (const handler of handlers) {
                handler(event.name, event.parameters);
            }
        }
    };

    document.addEventListener('click', (event) => {
        const element = event.target instanceof Element ? event.target.closest('[data-quire-event]') : null;

        // A form's event is its submit.
        if (element && !(element instanceof HTMLFormElement)) {
            send(element);
        }
    }, {capture: true});

    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && event.target.hasAttribute('data-quire-event')) {
            send(event.target);
        }
    }, {capture: true});
}
