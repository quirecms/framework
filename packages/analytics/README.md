# Quire analytics

Analytics for [Quire](https://quirecms.com): Google Analytics 4, loaded only once visitors allow it, and events marked in plain HTML.

It runs through [quire/privacy](https://github.com/quirecms/privacy): Google Analytics is a service visitors are asked about ("Analytics"), the site's Content-Security-Policy allows Google's tag for it, and the privacy notice lists it. The owner turns it on by setting the measurement ID in the admin's settings ("Analytics"), or with `bin/console quire:settings:set quire-analytics.ga4_id G-ABC123DEF4`.

- gtag.js directly, never Tag Manager. Consent Mode v2, basic: it loads only with consent; advertising, Google signals and ad personalisation are off.
- The page's address and the referrer go without their query string, except campaigns (`utm_*`) and the parameters the owner keeps (`quire-analytics.ga4_keep`).
- Nothing is sent from previews, a theme being tried, error pages, sign-in and account pages, the admin, outside production, or on another domain than the site's own (quire/privacy's test mode).
- Withdrawing stops it and removes its cookies.
- Events: `<a href="/contact" data-quire-event="contact_click" data-quire-event-place="footer">` sends `contact_click` with `{place: "footer"}`, only once the visitor allowed analytics.

See the [documentation](https://github.com/quirecms/framework/blob/main/docs/analytics.md).

License: MIT.
