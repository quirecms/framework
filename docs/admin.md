# Admin

The admin interface, the extension `quire-admin` (package `quire/admin`), manages the site in the browser at `/admin`. `quire:install` enables it on new sites; on others, enable it like any extension:

```bash
bin/console quire:extension:enable quire-admin
```

> **Status:** the admin is being built. It has its layout, menu, dashboard, content lists, editor with content checks, changes to published content with review and history, and the site's settings, theme and extensions; users and permissions follow.

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

## Editing

Choosing an item in a list opens it in the editor; "New" in a list starts one. The form comes from the content type: the title, the text, the excerpt, the item's address and author, its parent for pages, and the type's fields, grouped and ordered as the type declares them (`group`, `order`), with their labels and help.

- **The text is Markdown,** in an editor with a toolbar, shortcuts (Ctrl+B, Ctrl+I, Ctrl+K) and lists that continue as you type. Its preview is rendered by Quire, as the site shows it, shortcodes included, while you write.
- **Each field has the control its type asks for:** a number, a checkbox, a choice, a date, tags, JSON, or a list of items to link to, which you can search. Extensions that add field types add their controls too.
- **Dates and times are in the site's time zone,** the setting `site.timezone` (UTC unless set), e.g. `bin/console quire:settings:set site.timezone Europe/Berlin`.
- **Checks** of the content, e.g. images without a description or links to pages visitors can't see, are listed as you write, with links to where they are. Errors block publishing; warnings don't (see [checks](content.md#checks-before-publishing)).
- **Save, or save and publish, schedule, or take another step of the workflow.** What you may do is offered, nothing else. Mistakes are listed above the form and shown at their fields; nothing is saved until they're fixed.
- **No changes are lost:** when someone else saved the item after you opened it, saving shows you the form again with what you entered, unsaved, and tells you. Saving once more replaces their changes, knowingly.

Everything works without JavaScript: the text is then a plain text box, and "Preview" shows the preview without saving.

## Changing published content

Visitors never see half-written changes. Editing a published item writes **your changes**, saved as you type, and the site keeps showing the published version until they're published:

- **Publish changes** when they're ready, or **schedule** them for a date and time; at that time the background worker publishes them. Both take the ability to publish (`content.<type>.publish`).
- **Ask for a review:** reviewers (`content.<type>.review`, editors by default) find the changes on their dashboard, see what changes line by line, and approve them, ask for changes or comment. When the site asks for approval (the setting `quire.content.changes_need_approval`), changes are published only once someone other than their author approved them.
- **Several people can change an item at once,** each with their own changes. When changes are published, they're merged with what was published since, line by line: they only **conflict** where both changed the same lines or fields. The editor then lists those parts, and "Keep my version" uses yours there and keeps the others' changes everywhere else.
- **Discard** your changes to start again from the published version.
- When scheduled changes can't be published at their time, e.g. because of a conflict or a missing approval, they stay unpublished, and their author sees why in the editor and on the dashboard.

An item's **address and place** (slug, author, parent) aren't part of the changes: saving them changes the published item right away, which takes the ability to publish.

**History** lists an item's saved versions, newest first, with what each changed. Restoring one brings it back: right away for a draft, and as your changes, to look at and publish, for a published item.

## Managing the site

Under "Site" in the menu, for admins: each page takes its own ability, `quire.settings.manage`, `quire.themes.manage` and `quire.extensions.manage`, which no other role has by default. Grant one to a role to share that part, e.g. `bin/console quire:permissions:grant manager quire.settings.manage`.

- **Settings** are grouped as they're declared (an extension's under its own name), each with its label, help and the control its type asks for. Settings that an environment variable sets are shown with the variable's name, but can't be changed here. Sensitive settings, e.g. API keys, are never shown: entering a value replaces them, and leaving the field empty keeps them. "Back to the default" appears for settings the site changed. The levels of the [content checks](content.md#checks-before-publishing) are chosen per check.
- **Themes** lists the installed themes, the one in use first. "Use this theme" makes another the site's theme, as `quire:theme:use` does (see [themes](themes.md)): it's checked, and the site is rebuilt, which takes a few seconds.
- **Extensions** lists the installed ones (install them with Composer) and enables and disables them, as `quire:extension:enable` and `disable` do (see [extensions](extensions.md#managing-extensions)). Before anything changes, a page lists what else changes with it: the extensions an extension requires are enabled too, and those that require it are disabled too. "Delete its data" also deletes the data of the extensions it disables, their tables and settings, for good: type the extension's ID to confirm. Disabling `quire-admin` turns off the admin itself; enable it again with `bin/console quire:extension:enable quire-admin`.

When the theme and extensions are pinned in configuration, as for immutable production images, the pages say so and change nothing: change the configuration and redeploy instead.

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
