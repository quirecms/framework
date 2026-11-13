# Privacy and cookies

`quire/privacy` asks visitors before a service of another company runs on the site: analytics, embedded videos, fonts from a font service. A site that uses no such service doesn't need it, and shows nothing.

Install it with what needs it: an extension or theme that loads such a service requires it.

```bash
composer require quire/privacy
bin/console quire:extension:enable quire-privacy
```

## What visitors see

- **A banner** at the bottom (or in a corner: a setting), first after the theme's skip link. It asks only about the categories the site's services use:
  - one category, by its name: "Analytics cookies", with **Accept analytics cookies** and **Reject analytics cookies**, and **Cookie details**;
  - more: **Accept all**, **Reject all** and **Choose cookies**.

  Accepting and rejecting look the same, side by side, and nothing is switched on in advance. The page keeps room for the banner, so it covers nothing.
- **The cookie settings**, a dialog: each category with its switch and what it's for, and each service with who runs it, what it does, the other sites it reaches, what it keeps on the visitor's device and for how long, and its privacy policy. All of it comes from the services' declarations, so it's never out of date.
- **"Cookie settings"** on every page, in the footer, reopens the dialog: withdrawing is as easy as giving. Withdrawing stops the service where it can, and removes its cookies on the site's domain.
- **Asked again** about a service added since, or one that changed since the visitor allowed it (it reaches another site, keeps another cookie, or moved to another category), by name; other answers stay. A change of wording isn't a reason to ask again. Everyone is asked again when their choice expires.
- **No banner for browsers that ask not to be tracked** (Global Privacy Control, Do Not Track): they count as rejecting, and the dialog says why.
- **Not on pages with forms for passwords or accounts** (sign-in, registration, password resets): no banner, no loader, and nothing of a service runs there, whatever the visitor allowed.
- **No banner without JavaScript**, and no service runs: the banner stays hidden rather than show buttons that do nothing.
- **Test mode:** outside production, or on another domain than the site's own (`DEFAULT_URI`, e.g. a temporary domain), visitors are asked as usual, nothing is sent, and the banner says so. A site that answers on two domains without redirecting one to the other (e.g. with and without `www`) sends nothing on the other one: redirect it. To check a setup on a temporary domain, turn on `quire-privacy.send_in_test`.

The banner is in English, German and Macedonian (German and Macedonian are drafts until a native speaker checks them).

## Settings

Under "Privacy and cookies" in the admin's settings, or with `bin/console quire:settings:set`:

| Setting | Default | |
|---|---|---|
| `quire-privacy.layout` | `bar` | `bar` at the bottom, or `corner` |
| `quire-privacy.reject_wording` | `reject` | How rejecting reads: "Reject …", `necessary` ("Necessary only") or `technical` ("Technical cookies only"). They mean the same |
| `quire-privacy.choice_months` | 6 | How long a choice that allows something is kept (at most 12) |
| `quire-privacy.ask_again_months` | 6 | How soon visitors who allowed nothing are asked again (at most 12) |
| `quire-privacy.honour_signals` | on | Global Privacy Control and Do Not Track count as rejecting |
| `quire-privacy.send_in_test` | off | Services run after consent outside production and on other domains too, e.g. to check a setup on a temporary domain. What they send counts as real |
| `quire-privacy.privacy_page` | | The site's privacy notice, linked from the cookie settings, e.g. `/privacy` |
| `quire-privacy.log_months` | 12 | How long each choice is kept as proof, with what visitors were told (at most 60); never less than the choice itself lasts |

## The admin's page

With `quire/admin`, "Privacy and cookies" under Site (the ability `quire-privacy.manage`, for admins) shows:

- **What visitors see**, in words: which services the banner asks about, or that there's no banner; whether test mode is on; and a warning when the site has services but no privacy notice is set.
- **The services visitors are asked about:** who runs them, which extension or theme declared them, the sites they reach, and how many visitors allowed and refused each in the last 30 days.
- **Necessary services that reach other sites,** apart: they run without asking, because their extension or theme says the site can't work without them. Check each one: if it isn't truly needed (e.g. it tracks visitors), it must be asked about. Tell its maker, or disable it.
- **A preview** of the banner and the cookie settings in the site's look, without scripts.
- **A visitor's choices,** by the ID of their choice (below).

## The privacy notice

The site's privacy notice should list its services. Rather than copy them, put `[[privacy_services]]` in the notice's text, or `{{ quire_privacy_services() }}` in a template: the list comes from the services' declarations each time the page is shown, so it's never out of date. It gives each service's company, purpose, the sites it reaches, how long its company keeps the data, its privacy policy, and what it keeps on the visitor's device. Set the notice's address in `quire-privacy.privacy_page`, and the cookie settings link to it.

## Proof of consent

A site may have to show what a visitor agreed to. Each time a visitor chooses, the loader sends the choice to the site (`POST /_quire/privacy/consent`), which keeps:

- the choice's random ID (the one in the cookie; never the visitor's account or address);
- each answer: the service's revision allowed, or a refusal;
- when;
- what visitors were told then: each service's declaration, kept once per revision of the site's services, so the proof holds after the services change.

Visitors find their choice's ID in "Cookie settings"; the admin's page looks it up and shows each choice with what the visitor was told. A daily job removes choices older than `quire-privacy.log_months` (12 by default, at most 60), but never while the choice can still hold: a visitor's cookie lasts `choice_months` (or `ask_again_months` for a refusal) of 31 days each, and the proof is kept at least that long, whatever the setting. What visitors were told goes with the last choice that needs it.

The pages are the same for every visitor (cached), so the request carries no form token. Instead:

- it's taken only from the site's own pages: the browser's `Sec-Fetch-Site: same-origin`, or, from a browser that doesn't send it, an `Origin` equal to the site's;
- each answer must be about a service visitors are asked about, as it is now: a "yes" to another revision, an unknown service or a necessary one is refused;
- a choice is small: a body over 8 KB isn't read;
- a choice is logged at most 10 times an hour (a visitor saves a few times), and an address at most 300 choices an hour (five a minute: many visitors can share one address, e.g. a mobile carrier's or an office's). Addresses are counted for that hour only, in the cache, and never kept with the choice.

A choice over a limit isn't logged, and the site's log says so (at most every ten minutes, without the address). A site behind a proxy or load balancer must trust it (`SYMFONY_TRUSTED_PROXIES`; Quire Server sets this up): otherwise every visitor has the proxy's address, they all share one limit, and most choices go unlogged on a busy site.

The log is proof of what the site's pages sent; it doesn't stop someone who controls a browser from logging choices of their own making.

## Declaring a service

An extension or theme declares each service it loads, in its `extend.php`, and requires `quire-privacy` in its manifest (`"requires": ["quire-privacy"]`), or declares it only when privacy is enabled (`Extend\Conditional::whenExtensionEnabled()`):

```php
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

return [
    new Privacy()->service(
        'acme-video',
        label: 'Acme Video',
        category: Category::Marketing,
        vendor: 'Acme Video Ltd',
        purpose: 'Plays the videos in posts.',
        cookies: [new Cookie('acme_id', '1 year', 'Recognises you across sites.')],
        hosts: ['frame-src' => ['https://player.acme-video.example'], 'img-src' => ['https://thumbs.acme-video.example']],
        retention: '1 year',
        policyUrl: 'https://acme-video.example/privacy',
        script: 'acme-video/start.js',
        config: ['channel' => 'acme'],
    ),
];
```

- **Categories:** `Necessary` (never asked about), `Preferences`, `OwnStatistics` (the site's own measurement, kept apart for when the law exempts it; until then asked about like the others), `Statistics` (visitors see "Analytics") and `Marketing`.
- **Hosts** are allowed by the site's [Content-Security-Policy](security.md) on its pages, never on the sign-in pages. A host that no service declares can't load. The policy is the same for every visitor, so pages stay cacheable: it says what may load, and consent says what does.
- **The script** is a JavaScript module among the extension's assets, started only once the visitor allows the service. It may export `start({config, consent})` (e.g. to load the vendor's script, whose host is declared) and `stop()`. `config` is the declaration's, the same for every visitor: never a secret.
- Mistakes (a keyword as a host, a script that isn't an asset, a necessary service with a script, the same ID twice) are refused when the container is built, naming the extension.

Don't load a vendor's script, stylesheet, font or frame by its URL in a template: it would load before consent. Load it from the service's script. The site refuses to build while a template of the site, its theme or an enabled extension loads a service visitors are asked about directly (a theme switch then rolls back and says why, and a deploy fails), and `quire:theme:lint` names the line. Only URLs written in templates are seen, not those made in Twig.

## Embeds

What a trusted shortcode prints (e.g. a video player's `<iframe>`) waits in a placeholder when it loads from a service visitors are asked about: the placeholder says who it's from, and that loading it sends them the visitor's address and lets them set cookies, with a link to their privacy policy. Visitors choose:

- **Load it:** this embed, this time; nothing is kept.
- **Always allow {service}:** a "yes" for the service, kept and logged like one given in the settings. Its embeds load at once from then on, and go back to their placeholder when the visitor withdraws.

Without JavaScript, the placeholder links to the embed on the vendor's site. In test mode, nothing loads, and the placeholder says so. The placeholder is the same for every visitor, so pages stay cacheable.

A shortcode needs nothing for this: its output passes through `quire/privacy`'s guard. Everything it printed goes into one placeholder, exactly as printed, as data (JSON) that the loader reads with the browser's own parser when the embed loads. When that's from several services, or from a URL whose host can't be told, or it has a frame's own document (`srcdoc`), a `<noscript>` (which browsers read differently with JavaScript on) or styles that load something (`url()`, `@import`), it's held too, and only "Load it" is offered. Hosts of necessary services, the site's own URLs and hosts no service declares (which the policy blocks anyway) are shown as they are. When the embed loads, its scripts run as they would on the page: one from a declared host runs, inline code is blocked by the policy.

Output that isn't UTF-8 can't be carried or checked: its placeholder says the content can't be shown, and the site's log says so.

Editors can't embed by writing HTML: the sanitiser removes frames and scripts from content. Themes restyle the placeholder (`.consent-embed`, with the `--quire-consent-*` properties) or override `templates/extensions/quire_privacy/embed.html.twig`, keeping its `data-consent-embed*` attributes.

Analytics comes this way too: `quire/analytics` declares Google Analytics 4 as a service of the site ([analytics.md](analytics.md)).

## In PHP and JavaScript

On the server, for what PHP does on the visitor's behalf, e.g. a server-side event that must be dropped without consent:

```php
use Quire\Privacy\Consent;

if ($consent->allows($request, 'acme-video')) { … }
```

In the browser, `window.quire.consent`:

- `allows('acme-video')` or `allows('marketing')`;
- `set({'acme-video': true})` or by category, e.g. from a theme's own controls;
- `services()`: each with its ID, label, category and whether it's allowed;
- `open()`: the cookie settings;
- the `quire:consent` event on `document`, with `detail.allowed` and `detail.withdrawn` (service IDs).

The choice is kept in the first-party cookie `quire_consent`: for each service, the revision the visitor allowed (what it reached and kept then) or a refusal; when; and a random ID of the choice (never the visitor's account). A "yes" holds for the service as it was: once it changes, it counts as unanswered, on the server too, until the visitor answers again.

## Theming

- **Styles:** the banner inherits the page's font and uses the system's colours, so it fits a theme that styles nothing. A theme maps its look onto the `--quire-consent-*` custom properties (background, text, border, button colours, radius, gap, focus). Accepting and rejecting share one button style, `consent__btn`: there are no separate accept and reject styles.
- **Blocks:** override `templates/extensions/quire_privacy/consent.html.twig` with `{% extends '@!quire_privacy/consent.html.twig' %}` and the blocks `consent_heading`, `consent_text`, `consent_actions`, `consent_details_link` or `consent_dialog`. Keep both actions on the first layer, with names.
- **Checked:** `quire:theme:lint` and the admin's themes page render a theme's own banner with one kind of service and with several, and report it as an error unless the first layer has one accept and one reject button with the same classes, words, and neither hidden, and a way to the details (`[data-consent-open]`), and the settings have saving and rejecting. Comparing classes stands in for "they look the same": styles by ID or position can still tell them apart, so don't.
- **The opener:** put `{{ quire_consent_settings() }}` where the theme wants "Cookie settings"; without it, it goes in the footer slot.
