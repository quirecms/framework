# Content-Security-Policy

Quire sends the site's Content-Security-Policy. A policy tells browsers what a page may load and run, so a script someone slips into a page, or a theme that loads something from another site by mistake, is refused by the browser.

Quire is the only thing that sets the policy. Browsers apply every policy a response has and allow only what all of them allow. So a second policy, e.g. from a web server's configuration, quietly blocks what Quire's allows. Don't set one in Caddy, nginx or a controller of your own: change Quire's with "quire.csp" (below).

Only Quire's own pages get its policy: the site's, the admin's, and those of extensions. When Quire is part of another Symfony application, the application's own pages keep whatever they send.

## What pages may load

The site's pages, rendered with its theme (and the 404 and error pages), get:

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
```

- **Scripts, styles, images, fonts and requests from the site itself.** Images may also be `data:` URLs.
- **No inline code.** No `<script>` with code inside, no `onclick=` and the like, no `javascript:` links, no `<style>` elements and no `style` attributes. Put them in the theme's files (see [Assets](themes.md#assets)). `quire:theme:lint` warns about each one, with its line.
- **The importmap is allowed by its hash.** AssetMapper prints the importmap and the scripts that start the site's JavaScript inline. Quire adds each one's SHA-256 hash to `script-src`. Hashes are the same for every visitor, so pages stay cacheable. AssetMapper writes a stylesheet that a script imports (`import './styles/app.css'`) as a `data:` script, which the policy refuses (allowing `data:` scripts would let injected ones run). On the site's pages Quire links such a stylesheet in the head instead, and maps the import to an empty module of the site's (`/_quire/empty.js`). JSON files imported by scripts are still `data:` scripts: fetch them instead. This follows the format AssetMapper writes today (a test runs it against AssetMapper's real output, so a change fails there first), and goes once AssetMapper writes such modules as files ([symfony-docs#21356](https://github.com/symfony/symfony-docs/pull/21356)).
- **Public disks elsewhere** (S3, a CDN; see [storage](storage.md)) are added to `img-src` and `media-src`, so their images and videos show.
- **Forms post to the site only, and other sites can't show its pages in a frame.**
- **Nothing from other sites** unless the site's configuration allows it, or `quire/privacy` declares a service that needs it (analytics, embedded videos, fonts from a font service). Services of `quire/privacy` are the ones visitors are asked to consent to, and that the privacy page lists. (`quire/privacy` is being written; until it's out, only the site's configuration adds other sites.)

Other responses get policies of their own:

| Pages | Policy |
|-------|--------|
| Signing in, registering, resetting a password | The pages' policy, with `frame-ancestors 'none'`: never shown in a frame, where another site could capture what's typed. And none of `quire/privacy`'s services' hosts, whatever the visitor consented to: a script on a password form could read the password. The site's own `quire.csp.sources` still apply |
| Uploaded files (`/storage/…`) | `default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox`: an uploaded HTML or SVG file never runs scripts as the site. Caddy sends the same for the files it serves itself |
| The admin | `base-uri 'self'; object-src 'none'; frame-ancestors 'self'` |
| JSON, feeds and other data | None |

## Adding to the policy

What the site's pages may also load goes in `config/packages/quire.yaml`, by directive:

```yaml
quire:
    csp:
        sources:
            img-src: ['https://images.example.com']
            frame-src: ['https://www.youtube-nocookie.com']
            # Let a partner's site show the pages in a frame (never the sign-in pages)
            frame-ancestors: ['https://partner.example']
```

Each source is checked when the container is built: a host (`https://cdn.example.com`, `https://*.example.com`), a scheme (`https:`), or a keyword in quotes (`"'unsafe-inline'"`). A source can't add a directive of its own (`;`) or a second policy (`,`).

A third-party service that visitors should be asked about (analytics, an embedded video that sets cookies) belongs in `quire/privacy` instead: there the banner asks first, and the privacy page lists it.

## Trying a policy out

```yaml
quire:
    csp:
        mode: report-only
        report_uri: 'https://reports.example.com/csp'
```

The pages then get their policy as `Content-Security-Policy-Report-Only`. Browsers block nothing, and report what they would block to `report_uri` (and in their console). The sign-in pages still can't be framed. Uploaded files are always sandboxed.

## For extensions and themes

- **Scripts and styles belong in files**, added with `Extend\Assets`. They're loaded from the site, which the policy allows.
- **A route can ask for the sign-in pages' policy**, for a page with a form for passwords or accounts:

  ```php
  use Quire\Csp\Csp;

  new Extend\Routes()
      ->add('/members/join', 'acme_members_join', JoinController::class, ['GET', 'POST'], defaults: [Csp::ATTRIBUTE => 'private']);
  ```

- **Inline code that only one viewer sees** (a bar, a one-off script) can use the response's nonce. The response is then never cached, since a nonce must never be seen twice:

  ```php
  $nonce = $csp->nonce($request);   // Quire\Csp\Csp
  $html = sprintf('<script nonce="%s">…</script>', htmlspecialchars($nonce));
  ```

- **Inline code that is the same for everyone** can be allowed by its hash instead, which keeps the page cacheable: `$csp->allowScript($request, $code)`, with the exact text between `<script>` and `</script>`.
- **Other sites' hosts can't be added by an extension.** There's no extension point for it: a host added that way would load before the visitor's consent, and be missing from the privacy page. Only `quire/privacy` fills the policy's slot for other sites (`Quire\Csp\ThirdPartySourcesInterface`); the container refuses any other implementation, also one that decorates the slot or core's own sources, and says why. Declare the service through `quire/privacy`.
- **Don't set the header yourself.** A response that has its own `Content-Security-Policy` gets Quire's instead, and the log says so.
