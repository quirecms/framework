# Admin

The admin interface, the extension `quire-admin` (package `quire/admin`), manages the site in the browser at `/admin`. `quire:install` enables it on new sites; on others, enable it like any extension:

```bash
bin/console quire:extension:enable quire-admin
```

## Who can use it

Users with the ability `quire.admin.access` can open the admin: editors and admins by default. Guests are asked to sign in first, and other users get "403 Forbidden". Give the ability to other roles like any ability:

```bash
bin/console quire:permissions:grant moderator quire.admin.access
```

What each user can do in the admin follows their other abilities: someone who may only edit posts sees only posts.

## Find or do anything

The box at the top of every page (or Ctrl+K, ⌘K on a Mac) finds what you type among what you may open: content by its text, people by name, username or email, settings by their name, and the admin's pages. Choose with the arrow keys and Enter; Esc or a click beside it closes it.

It also understands what to do, in your words, and shows the plan before anything happens:

| You type | It does |
|---|---|
| `publish spring draw` | publishes the item |
| `schedule venue guide friday 18:00` | schedules it, in the site's time zone (also `tomorrow at 9`, `in 2 hours`, `30 oct 18:00`, `2026-12-24 09:00`) |
| `unpublish results`, `trash old news`, `restore old news` | takes it off the site, moves it to the trash, brings it back |
| `suspend kim`, `let kim in again` | suspends or lets in the person |
| `invite ana@example.org, ben@example.org as editors` | invites them, with the role |
| `use the theme Default` | switches the site's look |
| `set site title to Tournament News` | changes the setting |

German works too, e.g. `veröffentliche spring draw` or `plane venue guide freitag 18:00`. "Do it" runs the action the plan names, with your abilities, into the [audit log](audit.md), as the [actions](extensions.md#extendactions) any extension can call do; what you may not do, or what doesn't fit (a time that's past, a person suspended already), says why instead. With "Under the hood" on, the plan shows the command that does the same from a terminal. Without JavaScript, the box opens the same as a page.

## Home

Home greets you with what happened since yesterday, and then:

- **This week,** Monday to Sunday along a line: solid up to now, with what went live, dashed after, with what's scheduled to go live and changes scheduled (in the review colour while they wait for a review). Each opens its item.
- **Needs you:** what waits for you, most pressing first, each with the one thing to do: others' changes to review, your changes a reviewer asked you to change, your scheduled changes that couldn't go live, and items scheduled within a week that the checks found something in (first those with errors, which stop them going live). Extensions add theirs. The count is beside Home in the menu on every page.
- **Latest:** the latest entries of the [audit log](audit.md), for those who may read it.
- **Site health,** for those who manage settings: whether background tasks run (and what to do when they don't) and when the site was last backed up.
- Cards that extensions add.

A new site, with nothing live yet, starts with its **first steps** instead: naming it, writing the first post, inviting someone, and choosing a look, each a link to the real page; the steps left stay beside the rest afterwards.

## Content

Every content type has a list, under "Content" in the menu, for those who may edit its items: those with `content.<type>.edit_any` see all of them, those with only `edit_own` their own.

- **Tabs:** *Needs me* (changes waiting for your review, your own changes sent back or that couldn't be published at their time, and your items that can't be published as they are), *My drafts*, *Everything*, and the trash, each with how many items it has.
- **Filters with counts:** where items are in their lifecycle (draft, in review, scheduled, published, archived, and statuses extensions add), what the [content checks](content.md#checks-before-publishing) found, the language when there are several, and the type's filterable fields with few values (choices, yes or no, lists, links to other items). Choose several values of a filter for any of them. Each filter counts what you'd find with the others, so you see what else there is. Chosen values show in the search box; × takes one off.
- **Search** as you type: titles count most, then excerpts and bodies, in each item's language. With "Under the hood" on, the box shows the filters as words you can type, e.g. `tag:draw status:draft check:any lang:de`.
- **Each row** says where the item is (with the date when it's scheduled), what the checks found ("Broken link", "2 to check"), changes waiting, and the **next step**: review the changes, see what to change, fix what the checks found, an extension's step, or publish. Other actions are in the row's ⋯ menu.
- **Sorting** by title, by the dates (grouped by month, with how many items each month has), and by fields declared `sortable`; choosing a column's heading again reverses it. The latest change comes first otherwise.
- **Acting on several items:** select them (with ⇧ for a range), and the selection bar says what each action would apply to. *Select all … that match* takes the action on everything the filters find; more than 100 items are done in the background, as you, and the [audit log](audit.md) shows each one.
- **More as you scroll:** 50 items at a time.

*In motion* shows the same content as a board: drafts, changes in review, what's scheduled, and what went live this week, the latest of each with how many there are. The *Calendar* shows a month of what was published and what's scheduled, and changes scheduled to be published, in the site's time zone; on phones, the days with something.

The list's address keeps its tab, filters, search, sort and page (e.g. `/admin/content/post?view=mine&f[status][]=draft&q=markdown`), so it can be bookmarked and shared. Everything works without JavaScript, with pages and a menu of actions; with it, the selection bar, scrolling for more, and keys: <kbd>j</kbd>/<kbd>k</kbd> move, <kbd>x</kbd> selects, <kbd>e</kbd> edits.

## Editing

Choosing an item in a list opens it in the editor; "New" in a list starts one. The editor is a manuscript: the title and the text, as they read, with the item's details beside them. The details come from the content type: the summary (excerpt), the item's address and author, its parent for pages, and the type's fields, grouped and ordered as the type declares them (`group`, `order`), with their labels and help. A new item's address (its slug) follows its title until you write one; "change", beside the address under the title, goes to it.

- **The bar at the top** says where the item is (a draft, scheduled, live, live with your changes waiting) and when it was saved, and has what to do next: save, ask for a review, and publish. Publishing opens what the checks found first, and the choice of publishing now or at a time.
- **The text is Markdown, written as it reads:** emphasis, code and links show as they will (their marks appear only where the cursor is in them), headings and quotes keep their marks, and images show as a box with their description. Type `/` at the start of a line for a heading, an image, a link, a list, a quote or a separator; the toolbar, above the text while you write, and shortcuts (Ctrl+B, Ctrl+I, Ctrl+K) do the same. Bold, italic and code show as pressed where the cursor is in them; pressing them again takes the formatting off. "Preview" renders it as the site shows it, shortcodes included.
- **Notes in the margin,** beside their lines: what the checks found, and comments on your changes, which you can reply to and resolve.
- **Where it will appear,** beside the text, updated as you write: the item in lists (the site theme's card, see [themes](themes.md)), in search results, and when shared.
- **Its versions,** along a line above the text, the live one marked: each opens in History, to compare and restore.
- **Focus** hides everything but the manuscript.
- **On a phone, writing is a screen of its own,** without the top bar and the menu: the title and the text, with the formatting (heading, bold, italic, link, image, list, quote, code) on top of the keyboard. "Next" leads to the rest: what the checks found, where it will appear, the details, saving and publishing; "Back to the text" returns.
- **Each field has the control its type asks for:** a number, a checkbox, a choice, a date, tags, JSON, or a list of items to link to, which you can search. Extensions that add field types add their controls too.
- **Dates and times are in the site's time zone,** the setting `site.timezone` (UTC unless set), e.g. `bin/console quire:settings:set site.timezone Europe/Berlin`.
- **Checks** of the content, e.g. images without a description or links to pages visitors can't see, show as notes in the margin as you write, and on the publish button with how many there are. Errors block publishing; warnings don't (see [checks](content.md#checks-before-publishing)).
- **Save, or save and publish, schedule, or take another step of the workflow.** What you may do is offered, nothing else. Mistakes are listed above the form and shown at their fields; nothing is saved until they're fixed.
- **No changes are lost:** when someone else saved the item after you opened it, saving shows you the form again with what you entered, unsaved, and tells you. Saving once more replaces their changes, knowingly.

Everything works without JavaScript: the text is then a plain text box, the notes a list under it, and "Preview" shows the preview without saving.

## Changing published content

Visitors never see half-written changes. Editing a published item writes **your changes**, saved as you type, and the site keeps showing the published version until they're published:

- **Publish changes** when they're ready, or **schedule** them for a date and time; at that time the background worker publishes them. Both take the ability to publish (`content.<type>.publish`).
- **The text shows your changes** against the published version: what you added, and what you took out, struck through, with a bar beside the lines that changed.
- **Ask for a review:** reviewers (`content.<type>.review`, editors by default) find the changes under "Needs you" on Home and review them:
  - **What's live and the changes, side by side,** each changed word marked in its sentence, with unchanged lines folded away, and what else changed (the title, the summary, fields) below. On a phone, each change is a card: what's replaced, struck through, above what replaces it.
  - **The conversation:** the author's message, the reviews, and comments, those on text with what they quote; reply, resolve, or write a comment of your own.
  - **The checks,** what passed and what was found, marked when it isn't the changes' doing (the live version has it too).
  - **Approve or ask for changes,** with what you wrote as the review's message; on a phone, the review is a screen of its own, both at the bottom of it. **Comments** can point at text: select it in the manuscript and comment; the comment then sits beside it in the margin, moves with it as the text is edited, and shows the text it quoted once it's gone. Replies and "Resolve" settle them. When the site asks for approval (the setting `quire.content.changes_need_approval`), changes are published only once someone other than their author approved them.
- **Several people can change an item at once,** each with their own changes. When changes are published, they're merged with what was published since, line by line: they only **conflict** where both changed the same lines or fields. The editor then lists those parts, and "Keep my version" uses yours there and keeps the others' changes everywhere else.
- **Discard** your changes to start again from the published version.
- When scheduled changes can't be published at their time, e.g. because of a conflict or a missing approval, they stay unpublished, and their author sees why in the editor and on Home.

An item's **address and place** (slug, author, parent) aren't part of the changes: saving them changes the published item right away, which takes the ability to publish.

**History** lists an item's saved versions, newest first, with what each changed. Restoring one brings it back: right away for a draft, and as your changes, to look at and publish, for a published item.

## Managing the site

Under "Site" in the menu, for admins: each page takes its own ability, `quire.settings.manage`, `quire.themes.manage` and `quire.extensions.manage`, which no other role has by default. Grant one to a role to share that part, e.g. `bin/console quire:permissions:grant manager quire.settings.manage`.

- **Settings** read as a document: each group a paragraph, each setting a sentence ("This site is called Tournament News. It's written in English…"), using the phrase it's declared with (see [`Extend\Settings`](extensions.md#extendsettings)), or its label. Choose a value to change it in a small form with the control its type asks for, and its help; what differs from the default stands out, and a list beside the document says what changed and from what, with "Back to the defaults". Each group is also a form of its own ("as a form"). Settings that an environment variable sets show a lock and can't be changed here. Sensitive settings, e.g. API keys, are never shown: entering a value replaces them. The levels of the [content checks](content.md#checks-before-publishing) are chosen per check.
- **Moving settings between sites:** "Download" gives what the site changed as a YAML file (sensitive settings left out), and "Bring in a file…" takes one, from another site or your code, YAML or JSON, and shows every difference before "Change them" changes anything, as `quire:settings:export` and `quire:settings:import` do (see [settings](settings.md)).
- **Look** shows the installed themes with their screenshots, the one in use first. "Preview" shows you the site in a theme, only you, until you stop (see [previewing a theme](themes.md#previewing-a-theme)); themes that can't be previewed say why. "Use this theme" makes another the site's theme, as `quire:theme:use` does (see [themes](themes.md)): it's checked, and the site is rebuilt, which takes a few seconds.
- **Extensions** shows each installed one (install them with Composer) with what it adds to the site, e.g. "2 content types · 3 settings · 4 admin pages", and enables and disables them, as `quire:extension:enable` and `disable` do (see [extensions](extensions.md#managing-extensions)). Before anything changes, a page lists what else changes with it: the extensions an extension requires are enabled too, and those that require it are disabled too. "Delete its data" also deletes the data of the extensions it disables, their tables and settings, for good: type the extension's ID to confirm. Disabling `quire-admin` turns off the admin itself; enable it again with `bin/console quire:extension:enable quire-admin`.

When the theme and extensions are pinned in configuration, as for immutable production images, the pages say so and change nothing: change the configuration and redeploy instead.

## Audit log and commands

**Activity**, in the menu, shows who did what and when, in the admin, on the command line and in background jobs, for users with the ability `quire.audit.view` (admins). See [audit log](audit.md).

- **Search** for a title, a person, or what was done in the admin's words, e.g. "published" or "settings".
- **Bars** show how much was done on each of the last 30 days; choosing one shows that day.
- **Who** and **About** narrow it to a person ("Me", or "Only mine" at the top), what happened automatically, or an area: content, people, sign-ins, roles, settings, the look, extensions. "Where and when" narrows it to the web, the command line or background jobs, and to dates.
- **Similar things fold together:** what was done at once, e.g. publishing several items from a list, and sign-ins one after another.
- **Undo** where it can be taken back, while nothing changed it since (see [undoing](audit.md#undoing)); for what was done at once, all of it.
- **Under the hood**, each entry shows its command, and **Export** downloads what's shown, one JSON object per line, as `quire:audit:list --format jsonl` does.

Everything the admin does, it does through the same services as the console commands. So after an action, those who may see the audit log get the command that does the same, e.g. `bin/console quire:user:suspend ada`, with a button to copy it: what you clicked once can go in a script.

## Users and roles

Under "People" in the menu, for admins: the list of people takes the ability `quire.users.manage`, "Roles" and "Check access" take `quire.permissions.manage`. No other role has them by default.

- **People** lists everyone, by role, those invited who haven't joined yet, and those suspended, each tab with how many. Each person shows their roles, their status (active, "new" in their first week, invited when, the invitation expired, or suspended), when they were last seen, and the one thing to do: their activity, sending the invitation again, or letting them in again. Search by name, username or email.
- **Invite people** beside the list takes one email or many, and the role they get, with what that role may do in a sentence. Each one is added and invited by email to choose their password; those already users are invited again (see [users](users.md#managing-users)). "Add a user" adds one person with every detail, and can set the password or leave them without one when they sign in another way, e.g. with single sign-on.
- **A user's page** changes their name, email, username and roles, suspends or activates them, and invites them again. Deleting a user asks who gets their content. You can't suspend or delete yourself, and the site's last active admin keeps the role.
- **See the admin as them** (with `quire.users.view_as`, admins by default) shows the admin, or the site for those who can't open the admin, as they see it, without being able to change anything; a bar on every page says so and stops it (see [permissions](permissions.md#seeing-the-site-as-someone-else)).
- **Roles** lists the roles with their users and how far each differs from its defaults. A role's page shows what it may do with content as a grid of content types and actions, then the other abilities by the extension that declares them, with a filter. A mark shows each choice that differs from the default, and "Back to the defaults" undoes them. Saving shows first what the role gains and loses, how many users that affects, and what deserves a second look: abilities given to everyone, including visitors, abilities that run the site, and abilities used in the admin by a role that can't open it. Admins have every ability except strict ones, so their page offers only those.
- **Your own roles** can be added, starting from another role's abilities, renamed and deleted (see [permissions](permissions.md#roles)).
- **Check access** tells whether a user may do something, and why (see [permissions](permissions.md#why-someone-may-or-may-not)), and which roles may.

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

**Finding your way:** the rail on the left (at the bottom on phones) has Home, Content, People, Activity and Site, and "More" for pages extensions add; a section's pages are tabs above them, e.g. Posts and Pages under Content. **＋ New** starts an item of any type you may create.

**Under the hood**, the switch at the top, shows what's behind the admin: the console command that does what you just did, and more as the admin grows. It's on for admins and off for others until each person switches it.

**Your preferences**, in the account menu (or press `?`), are each person's own and follow them to every device: light or dark (or like the system), contrast, text size, density, movement, the language they read the admin in (the site's by default; the admin speaks English and German), and "Under the hood". Each change shows at once. They're [people's preferences](extensions.md#extendpreferences), e.g. `bin/console quire:user:preferences ada quire.language de`.

**The keyboard** works across the admin, as in mail and code hosts: `g` then `h`, `c` or `p` goes home, to content or to people; `n` starts a new post; `j` and `k` move through a list and `x` selects the item; Ctrl+S (⌘S on a Mac) saves and Ctrl+Shift+P publishes in the editor; `?` shows them all. While you type in a field, only saving and publishing work.

The admin's look is **Quire UI**, its own design system: [Open Props](https://open-props.style) scales and Quire's tokens, with light, dark and the system's high-contrast setting, the Inter and Bricolage Grotesque fonts it ships, and no build step. It looks the same whatever the site's theme. To adjust it, add a stylesheet to the group `admin` (see [extensions](extensions.md#extendassets)) and override its tokens, e.g. `:root { --q-accent: #8250df; }`; Quire UI's styles are in cascade layers, so yours win. Its components, with their markup, are at `/admin/ui` (linked from the account menu with "Under the hood" on).

The admin is translated with the site's language (`site.locale`), in the domain `quire-admin`. A site overrides its texts in its own `translations/` directory.

## Adding to it

Extensions add pages, menu entries, what needs the user and cards on Home, and columns, filters and actions of content lists: see [admin pages](extensions.md#admin-pages).

Most extensions need none of that. The admin builds its screens from what extensions declare: a content type gets a menu entry, a list that sorts and filters by its fields, and a form with a control for each field; a field type picks the control that edits it (or adds one); settings get their page; abilities appear in the roles' pages; and the extension's actions, recorded in the [audit log](audit.md), are listed with everyone else's.
