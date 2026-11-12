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

Don't load a vendor's script in a template: it would run before consent. Load it from the service's script.

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
- **The opener:** put `{{ quire_consent_settings() }}` where the theme wants "Cookie settings"; without it, it goes in the footer slot.
