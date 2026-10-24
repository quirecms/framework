# Admin

The admin interface, the extension `quire-admin` (package `quire/admin`), manages the site in the browser at `/admin`. `quire:install` enables it on new sites; on others, enable it like any extension:

```bash
bin/console quire:extension:enable quire-admin
```

> **Status:** the admin is being built. It has its layout, menu and dashboard; managing content, settings, extensions, themes and users follow.

## Who can use it

Users with the ability `quire.admin.access` can open the admin: editors and admins by default. Guests are asked to sign in first, and other users get "403 Forbidden". Give the ability to other roles like any ability:

```bash
bin/console quire:permissions:grant moderator quire.admin.access
```

What each user can do in the admin follows their other abilities: someone who may only edit posts sees only posts.

## Its address

The admin is at `/admin` by default. When Quire runs in an application that uses `/admin` itself, move it in `config/packages/quire.yaml`:

```yaml
quire:
    routing:
        admin_prefix: /cms-admin
```

Pages that extensions add to the admin move with it.

## What it's built with

Every page is plain HTML rendered on the server, and works without JavaScript. [Turbo](https://turbo.hotwired.dev) makes moving between pages fast, and [Stimulus](https://stimulus.hotwired.dev) adds behaviour where a page needs it. Both ship with the extension, so it needs no Node.js and nothing to download. Light and dark follow the system's setting, and the pages are checked against WCAG 2.2 AA in CI.

The admin looks the same whatever the site's theme. To adjust it, add a stylesheet to the group `admin` (see [extensions](extensions.md#extendassets)) and override the design tokens it uses, e.g. `:root { --admin-accent: #8250df; }`.

The admin is translated with the site's language (`site.locale`), in the domain `quire-admin`. A site overrides its texts in its own `translations/` directory.

## Adding to it

Extensions add pages, menu entries and dashboard widgets: see [admin pages](extensions.md#admin-pages).
