// Quire's consent loader (quire/privacy). A static file from the site itself, so the site's
// Content-Security-Policy needs no nonce for it and pages stay cacheable. It reads what the page
// declares (#quire-consent-config), shows the banner to visitors who haven't chosen, keeps their
// choice in a first-party cookie, and starts a service's script only once it's allowed.
//
// A service's script is a JavaScript module of the site's. It may export:
//   start({config, consent}): run, e.g. load the vendor's script (its host is in the policy)
//   stop(): stop what it can (the loader also removes the service's first-party cookies)
//
// Themes and extensions can use window.quire.consent: allows(serviceOrCategory), set({id: bool}),
// services(), open(), and the "quire:consent" event on document (detail: {allowed, withdrawn}).

const element = document.getElementById('quire-consent-config');
const config = read(element);

if (config) {
    start(config);
}

function read(script) {
    try {
        const data = JSON.parse(script?.textContent ?? '');

        return data && data.v === 1 && Array.isArray(data.services) ? data : null;
    } catch {
        return null;
    }
}

function start(config) {
    const banner = document.querySelector('[data-consent-banner]');
    const dialog = document.querySelector('[data-consent-dialog]');
    const status = document.querySelector('[data-consent-status]');
    const services = config.services;
    const byId = new Map(services.map((service) => [service.id, service]));
    // Global Privacy Control or Do Not Track: nothing runs, no banner; the dialog says why.
    const signal = config.signals === true && (navigator.globalPrivacyControl === true || navigator.doNotTrack === '1' || window.doNotTrack === '1');
    const started = new Set();
    const modules = new Map();
    let choice = signal ? null : stored();
    let returnFocus = null;

    const api = {
        allows(id) {
            if (byId.has(id)) {
                return allowed(id);
            }

            return services.some((service) => service.category === id && allowed(service.id));
        },
        set(changes) {
            const next = current();

            for (const [key, value] of Object.entries(changes ?? {})) {
                for (const service of services) {
                    if (service.id === key || service.category === key) {
                        next[service.id] = value === true;
                    }
                }
            }

            save(next);
        },
        services() {
            return services.map((service) => ({id: service.id, label: service.label, category: service.category, allowed: allowed(service.id)}));
        },
        open() {
            openDialog(document.activeElement);
        },
    };

    window.quire = window.quire ?? {};
    window.quire.consent = api;

    for (const opener of document.querySelectorAll('.consent__opener[data-consent-open]')) {
        opener.hidden = false;
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-consent-accept], [data-consent-reject], [data-consent-save], [data-consent-open]') : null;

        if (!target || signal && !target.hasAttribute('data-consent-open')) {
            return;
        }

        const inBanner = banner?.contains(target) ?? false;

        if (target.hasAttribute('data-consent-open')) {
            openDialog(target);
        } else if (target.hasAttribute('data-consent-accept')) {
            save(everything(true, inBanner));
        } else if (target.hasAttribute('data-consent-reject')) {
            save(everything(false, inBanner));
        } else {
            save(fromDialog());
        }

        if (!target.hasAttribute('data-consent-open')) {
            if (dialog?.open) {
                dialog.close();
            }

            hideBanner(inBanner);
        }
    });

    dialog?.addEventListener('change', (event) => {
        const box = event.target;

        if (!(box instanceof HTMLInputElement)) {
            return;
        }

        const category = box.dataset.consentCategory;

        if (category) {
            for (const service of dialog.querySelectorAll(`[data-consent-of="${category}"]`)) {
                service.checked = box.checked;
            }
        }

        syncCategories();
    });

    dialog?.addEventListener('close', () => {
        // Back to what opened it, unless that went with the banner: then the opener, or the page.
        const visible = returnFocus !== null && returnFocus.isConnected && returnFocus.getClientRects().length > 0;
        (visible ? returnFocus : document.querySelector('.consent__opener:not([hidden])') ?? document.querySelector('main'))?.focus?.();
        returnFocus = null;
    });

    apply(null);

    if (!signal && (choice === null || unasked().length > 0)) {
        showBanner();
    }

    function allowed(id) {
        return !signal && choice !== null && choice.s[id] === true;
    }

    // Services added since the visitor chose: asked about by name, earlier answers kept.
    function unasked() {
        return choice === null ? services : services.filter((service) => !(service.id in choice.s));
    }

    function current() {
        return Object.fromEntries(services.map((service) => [service.id, allowed(service.id)]));
    }

    function everything(value, onlyNew) {
        const next = current();

        for (const service of onlyNew && choice !== null ? unasked() : services) {
            next[service.id] = value;
        }

        return next;
    }

    function fromDialog() {
        const next = current();

        for (const box of dialog?.querySelectorAll('[data-consent-service]') ?? []) {
            next[box.dataset.consentService] = box.checked;
        }

        return next;
    }

    function stored() {
        const name = `${config.cookie.name}=`;
        const raw = document.cookie.split('; ').find((cookie) => cookie.startsWith(name));

        if (!raw) {
            return null;
        }

        try {
            const data = JSON.parse(atob(raw.slice(name.length).replace(/-/g, '+').replace(/_/g, '/')));

            if (data.v !== 1 || typeof data.id !== 'string' || !Number.isInteger(data.t) || typeof data.s !== 'object' || data.s === null) {
                return null;
            }

            const s = {};

            for (const [id, value] of Object.entries(data.s)) {
                s[id] = value === true || value === 1;
            }

            const expires = new Date(data.t * 1000);
            expires.setMonth(expires.getMonth() + (Object.values(s).includes(true) ? config.months.choice : config.months.again));

            return expires > new Date() ? {v: 1, id: data.id, t: data.t, r: data.r, s} : null;
        } catch {
            return null;
        }
    }

    function save(next) {
        const before = choice;
        const months = Object.values(next).includes(true) ? config.months.choice : config.months.again;
        choice = {v: 1, id: before?.id ?? randomId(), t: Math.floor(Date.now() / 1000), r: config.revision, s: next};
        const value = btoa(JSON.stringify(choice)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        document.cookie = `${config.cookie.name}=${value}; Path=/; Max-Age=${months * 31 * 86400}; SameSite=Lax${config.cookie.secure ? '; Secure' : ''}`;
        const withdrawn = apply(before);

        announce(withdrawn.length > 0
            ? withdrawn.map((service) => config.messages.off.replace('{label}', service.label)).join(' ')
            : config.messages.saved);
    }

    // Starts what's allowed and stops what isn't; returns the services withdrawn just now.
    function apply(before) {
        const now = [];
        const withdrawn = [];

        for (const service of services) {
            const yes = allowed(service.id);

            if (yes && !started.has(service.id)) {
                started.add(service.id);
                now.push(service.id);
                run(service);
            } else if (!yes && (started.has(service.id) || before?.s[service.id] === true)) {
                started.delete(service.id);
                withdrawn.push(service);
                modules.get(service.id)?.stop?.();
                removeCookies(service);
            }
        }

        document.dispatchEvent(new CustomEvent('quire:consent', {detail: {allowed: now, withdrawn: withdrawn.map((service) => service.id)}}));

        return withdrawn;
    }

    async function run(service) {
        // In test mode visitors are asked as usual, and nothing is sent.
        if (config.test || !service.script) {
            return;
        }

        try {
            const module = await import(service.script);
            modules.set(service.id, module);
            module.start?.({config: service.config, consent: api});
        } catch (error) {
            console.error(`Quire: the service "${service.id}" didn't start.`, error);
        }
    }

    // The service's own cookies on the site's domain, where the browser lets a page remove them.
    function removeCookies(service) {
        const names = document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).filter((name) => service.cookies.some((pattern) => pattern.endsWith('*') ? name.startsWith(pattern.slice(0, -1)) : name === pattern));
        const parts = location.hostname.split('.');
        const domains = [''];

        for (let i = 0; i < parts.length - 1; i++) {
            domains.push(`; Domain=.${parts.slice(i).join('.')}`);
        }

        for (const name of names) {
            for (const domain of domains) {
                document.cookie = `${name}=; Path=/; Max-Age=0${domain}`;
            }
        }
    }

    function showBanner() {
        if (!banner) {
            return;
        }

        const added = choice === null ? [] : unasked();
        const note = banner.querySelector('[data-consent-new]');

        if (note && added.length > 0) {
            note.querySelector('[data-consent-new-names]').textContent = added.map((service) => service.label).join(', ');
            note.hidden = false;
        }

        banner.hidden = false;
        document.documentElement.classList.add('consent-shown');
        // The page keeps room for the banner, so it covers nothing at the end of the page.
        new ResizeObserver(() => document.documentElement.style.setProperty('--quire-consent-height', `${banner.offsetHeight}px`)).observe(banner);
    }

    function hideBanner(fromBanner) {
        if (!banner || banner.hidden) {
            return;
        }

        banner.hidden = true;
        document.documentElement.classList.remove('consent-shown');
        document.documentElement.style.removeProperty('--quire-consent-height');

        if (fromBanner) {
            const opener = document.querySelector('.consent__opener:not([hidden])');
            const main = document.querySelector('main');
            (opener ?? main)?.focus?.();
        }
    }

    function openDialog(opener) {
        if (!dialog) {
            return;
        }

        returnFocus = opener instanceof HTMLElement ? opener : null;

        for (const box of dialog.querySelectorAll('[data-consent-service]')) {
            box.checked = allowed(box.dataset.consentService);
            box.disabled = signal;
        }

        for (const box of dialog.querySelectorAll('[data-consent-category]')) {
            box.disabled = signal;
        }

        for (const button of dialog.querySelectorAll('[data-consent-accept], [data-consent-reject], [data-consent-save]')) {
            button.hidden = signal;
        }

        const note = dialog.querySelector('[data-consent-signal]');

        if (note) {
            note.hidden = !signal;
        }

        syncCategories();
        dialog.showModal();
    }

    function syncCategories() {
        for (const box of dialog?.querySelectorAll('[data-consent-category]') ?? []) {
            const members = [...dialog.querySelectorAll(`[data-consent-of="${box.dataset.consentCategory}"]`)];
            const on = members.filter((member) => member.checked).length;
            box.checked = members.length > 0 && on === members.length;
            box.indeterminate = on > 0 && on < members.length;
        }
    }

    function announce(message) {
        if (status) {
            status.textContent = '';
            // A change the screen reader notices even when the same text comes twice.
            requestAnimationFrame(() => {
                status.textContent = message;
            });
        }
    }
}

function randomId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
