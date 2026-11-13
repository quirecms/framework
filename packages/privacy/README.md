# Quire privacy and cookies

Privacy and cookies for [Quire](https://quirecms.com): the services of other companies that a site uses (analytics, videos, fonts), what visitors are asked about them, and the banner and settings dialog that ask.

A site that uses no such service never needs it, and shows nothing. Extensions and themes that load a service require it, and declare the service in their `extend.php`:

```php
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

new Privacy()->service(
    'acme-video',
    label: 'Acme Video',
    category: Category::Marketing,
    vendor: 'Acme Video Ltd',
    purpose: 'Plays the videos in posts.',
    cookies: [new Cookie('acme_id', '1 year', 'Recognises you across sites.')],
    hosts: ['frame-src' => ['https://player.acme-video.example']],
    policyUrl: 'https://acme-video.example/privacy',
    script: 'acme-video/start.js',
);
```

The declaration is the one source of what visitors read in the banner and the settings dialog, and of the hosts the site's Content-Security-Policy allows. A host no service declares can't load. The service's script (a JavaScript module of the extension's) runs only once the visitor allows it.

- The banner asks only about the categories in use: with one, by its name ("Accept analytics cookies", "Reject analytics cookies"); with more, "Accept all", "Reject all" and "Choose cookies". Accepting and rejecting look the same, and nothing is switched on in advance.
- "Cookie settings" reopens the visitor's choice on every page: in the theme's footer, or where the theme puts `{{ quire_consent_settings() }}`.
- Browsers that send Global Privacy Control or Do Not Track count as rejecting.
- Visitors are asked again when the services change (about what's new only), and when their choice expires.
- Outside production, or on another domain than the site's own, visitors are asked as usual and nothing is sent ("test mode").
- Embeds of trusted shortcodes from such a service (a video player) wait in a placeholder until the visitor loads them once or allows the service. A template that loads such a service directly stops the site from being built.
- Each choice is kept as proof, by its random ID, with what the visitor was told; the admin's "Privacy and cookies" page looks it up. `[[privacy_services]]` lists the services in the site's privacy notice.

Settings are under "Privacy and cookies" in the admin's settings. Themes restyle the banner with the `--quire-consent-*` custom properties, or override its blocks in `templates/extensions/quire_privacy/consent.html.twig`. See the [documentation](https://github.com/quirecms/framework/blob/main/docs/privacy.md).

The German and Macedonian texts are drafts until a native speaker has checked them.

License: MIT.
