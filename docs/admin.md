# Admin

The admin interface, the extension `quire-admin` (package `quire/admin`), manages the site in the browser at `/admin`. `quire:install` enables it on new sites; on others, enable it like any extension:

```bash
bin/console quire:extension:enable quire-admin
```

> **Status:** the admin is being built. It has its layout, menu, dashboard and content lists; editing content, settings, extensions, themes and users follow.

## Who can use it

Users with the ability `quire.admin.access` can open the admin: editors and admins by default. Guests are asked to sign in first, and other users get "403 Forbidden". Give the ability to other roles like any ability:

```bash
bin/console quire:permissions:grant moderator quire.admin.access
```

What each user can do in the admin follows their other abilities: someone who may only edit posts sees only posts.

## Content

Every content type has a list, under "Content" in the menu, for those who may edit its items: those with `content.<type>.edit_any` see all of them, those with only `edit_own` their own.

- **Views** by status (drafts, published, archived, and statuses that extensions add), and the trash, each with how many items it has.
- **Search** as you type a word: titles count most, then excerpts and bodies, in each item's language.
- **Sorting** by title, by the dates, and by fields declared `sortable`; choosing a column's heading again reverses it. The latest change comes first otherwise.
- **Actions** for an item, or for the selected ones at once: publish, unpublish and archive (with `content.<type>.publish`), move to the trash, restore and delete permanently (with `content.<type>.delete`), and those of the content type's workflow and of extensions. What isn't possible for an item, or not allowed, isn't offered; when an action fails for some of the selected items, the list says which and why.

The list's address keeps its view, search, filters, sort and page (e.g. `/admin/content/post?view=draft&q=markdown`), so it can be bookmarked and shared. Everything works without JavaScript; with it, a checkbox selects every row.

## Its address

The admin is at `/admin` by default. When Quire runs in an application that uses `/admin` itself, move it in `config/packages/quire.yaml`:

```yaml
quire:
    routing:
        admin_prefix: /cms-admin
```

Pages that extensions add to the admin move with it.

## What it's built with

Every page is plain HTML rendered on the server, and works without JavaScript. [Turbo](https://turbo.hotwired.dev) makes moving between pages fast, and [Stimulus](https://stimulus.hotwired.dev) adds behaviour where a page needs it. The site downloads both when the admin is installed, like the packages of its own importmap, so it needs no Node.js. Light and dark follow the system's setting, and the pages are checked against WCAG 2.2 AA in CI.

The admin looks the same whatever the site's theme. To adjust it, add a stylesheet to the group `admin` (see [extensions](extensions.md#extendassets)) and override the design tokens it uses, e.g. `:root { --admin-accent: #8250df; }`.

The admin is translated with the site's language (`site.locale`), in the domain `quire-admin`. A site overrides its texts in its own `translations/` directory.

## Adding to it

Extensions add pages, menu entries, dashboard widgets, and columns, filters and actions of content lists: see [admin pages](extensions.md#admin-pages).
