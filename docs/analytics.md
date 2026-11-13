# Analytics

`quire/analytics` adds Google Analytics 4 to a site, loaded only once visitors allow it, and events that templates mark in plain HTML. It runs through [`quire/privacy`](privacy.md): Google Analytics is a service visitors are asked about, under "Analytics".

```bash
composer require quire/analytics
bin/console quire:extension:enable quire-privacy
bin/console quire:extension:enable quire-analytics
```

## Google Analytics 4

In the admin, Settings, "Analytics": paste the site's measurement ID (Google Analytics: Admin, Data streams, the site's stream), e.g. `G-ABC123DEF4`, or `bin/console quire:settings:set quire-analytics.ga4_id G-ABC123DEF4`. Empty, Google Analytics is off: no question in the banner, none of Google's hosts in the policy, nothing in the privacy notice.

- **Who may set it:** those who manage settings and have the ability `quire-analytics.manage` (admins). It's the site's own setting: a theme or an extension can't set or switch it.
- **It's checked:** `G-` and capital letters and digits.
- **Changing it is in the activity log,** with who, when, and the ID before and after: it sends every visitor's data to another property.
- **Turning it on** asks visitors about Google Analytics only; their other answers stand. A visitor who allowed Google Analytics before it was turned off counts as allowing it again when it's turned back on: consent is to the service as declared (its hosts, cookies, category), not to a property. Changing the ID doesn't ask again.
- **Turning it off** takes effect with the next page: Quire's pages are sent with `Cache-Control: max-age=0, must-revalidate, private`, so browsers revalidate them and shared proxies don't keep them. If the site has an HTTP cache of its own in front (a CDN), pages it still holds load Google Analytics until they expire or are purged: purge it.
- **What visitors are told**, in the banner, the cookie settings and the privacy notice: Google Analytics, run by Google Ireland Limited (with Google LLC in the United States), counts visits; it keeps `_ga` and `_ga_*` for 2 years.
- **What loads:** gtag.js directly, never Tag Manager, whose container could add any script. The site's Content-Security-Policy allows scripts only from `https://www.googletagmanager.com/gtag/` (the tag, not a container), and Google's analytics hosts for its hits, so what a configuration on Google's side might add (advertising, other destinations) is blocked.
- **Consent Mode v2, basic:** it loads only once the visitor allows analytics. Advertising is denied, and Google signals and ad personalisation are off.
- **No tokens in the address.** The page's address and the referrer go without their fragment and without query parameters other than campaigns (`utm_*`): sign-in, reset and other links carry tokens and e-mail addresses. Keep others the site needs, e.g. its search's, in "Query parameters Google Analytics may see" (`quire-analytics.ga4_keep`, e.g. `q`).
- **Withdrawing** stops it at once (Google's `ga-disable-` switch: nothing more is sent, not even cookieless hits) and removes `_ga` and `_ga_*` on the site's domain and its parent domains.

## What's never counted

- the admin, and pages with forms for passwords or accounts (sign-in, registration, password resets): quire/privacy's loader isn't on them;
- previews of unpublished items, the site in a theme someone is trying, and error pages: their head says `<meta name="quire-analytics" content="off">`;
- outside production, and on another domain than the site's own (quire/privacy's test mode), unless the owner turns that off.

## Events

A template marks what matters in plain HTML, and Quire sends it to Google Analytics once the visitor allows it:

```twig
<a href="/contact" data-quire-event="contact_click" data-quire-event-place="footer">Contact us</a>
<form method="post" data-quire-event="newsletter_signup">…</form>
```

- A click sends the event, or, on a form, its submit; `data-quire-event-*` are its parameters (`data-quire-event-link-text` is `link_text`).
- An event before the visitor allows analytics is dropped, never kept for later.
- Google's limits apply: names of up to 40 letters, digits and `_`, starting with a letter; values of up to 100 characters; 25 parameters. A name that doesn't fit isn't sent.
- Only templates mark events: what editors write can't carry `data-*` attributes (the sanitiser removes them).

## Not yet

- Events sent by the server (GA4's Measurement Protocol), which need the visitor's consent passed along.
- The admin showing when the site last saw a hit, and not counting signed-in staff.
- Quire's own counter, without cookies or a banner (planned).
