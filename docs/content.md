# Content

Everything a Quire site publishes, from blog posts and pages to an extension's products or recipes, is **content**. Each item has a **content type**, which decides how the item behaves: whether it forms a tree, keeps revisions, appears in listings, and what its URL looks like.

## Content types

Quire core registers two types. Extensions can register more (see [`Extend\ContentType`](extensions.md#extendcontenttype)).

| Type | Behaves like | Default permalink |
|------|--------------|-------------------|
| `post` | Chronological articles: listed newest first, with excerpts and scheduled publishing | `/blog/{slug}` |
| `page` | A tree of standalone pages, ordered by position | `/{path}` (e.g. `/about/team`) |

To see every registered type, including those from enabled extensions:

```bash
bin/console quire:content-type:list
```

```
 ------ ------- -------------------------------------------------------------- -------------- ------------------- ---------------
  Type   Label   Capabilities                                                   Permalink      Sorted by           Registered by
 ------ ------- -------------------------------------------------------------- -------------- ------------------- ---------------
  post   Posts   revisions, scheduling, excerpt, listable, searchable, author   /blog/{slug}   published_at desc   core
  page   Pages   hierarchical, revisions, searchable, author                    /{path}        position asc        core
 ------ ------- -------------------------------------------------------------- -------------- ------------------- ---------------
```

### Capabilities

| Capability | Meaning |
|------------|---------|
| `hierarchical` | Items form a tree. Each item can have a parent and a position among its siblings, and permalinks can use `{path}`. |
| `revisions` | Each save keeps a revision that can be restored |
| `scheduling` | Items can be published at a future date |
| `excerpt` | Items have a short summary |
| `listable` | Items appear in paginated listings and feeds |
| `searchable` | Items are included in site search |
| `author` | Items have an author |

Extensions add their own capabilities, for example `commentable`, and can add them to types that other extensions register.

### Fields

Content types and extensions can declare **fields**: typed values such as a product's price, an event's date or a post's rating. Core has types for text, numbers, booleans, choices, lists, dates and JSON, and extensions add more, e.g. a location or a color (see [extensions](extensions.md#extendfieldtypes)). To see a type's fields and their rules:

```bash
bin/console quire:content-type:show post
```

Set fields with `--fields` on `quire:content:create` and `quire:content:update`, as a JSON object. On update, only the fields you pass change, and `null` removes one:

```bash
bin/console quire:content:create product "Mug" --fields '{"price": 12.5, "color": "blue"}'
bin/console quire:content:update <id> --fields '{"color": null}'
```

## Writing content

Bodies are written in **Markdown** by default ([CommonMark](https://commonmark.org) with GitHub's tables, task lists, strikethrough and autolinks). Pass `--format text` for plain text, or `--format html` for HTML. Whatever the format, the HTML visitors get is sanitised: scripts, styles, frames, forms, event handlers and `javascript:` links never get through. HTML inside Markdown is shown as text unless a profile allows it.

Headings get `id`s from their text, so you can link to sections: `## Getting started` becomes `#getting-started`.

### Teasers

A line with only `<!--more-->` ends the **teaser**, the part of a post shown in listings. Without it, listings show the excerpt: the item's own excerpt, or the start of its text.

### Shortcodes

Extensions add **shortcodes** that insert dynamic content, such as a gallery or a video:

```markdown
[[gallery id=3]]
[[video id="dQw4w9WgXcQ" caption="Our launch" autoplay]]
```

A shortcode alone in a paragraph replaces the paragraph. Shortcodes inside code (`` `[[gallery]]` ``) are shown as written, and so are those no enabled extension provides.

### Previewing

```bash
bin/console quire:content:render <id>              # the body as HTML
bin/console quire:content:render <id> --teaser     # the part before <!--more-->
bin/console quire:content:render <id> --excerpt    # the plain-text summary
```

Rendered HTML is cached until the item changes; shortcodes run each time. The cache is disposable: `bin/console cache:pool:clear quire.render_cache` empties it.

## Managing content from the command line

```bash
bin/console quire:content:create post "Hello world" --body "My *first* post." --publish
bin/console quire:content:create page "Team" --parent <page-id> --body-file team.md --author grace
bin/console quire:content:list --type post
bin/console quire:content:show <id>
bin/console quire:content:update <id> --title "Hello again" --message "Better title"
bin/console quire:content:transition <id> unpublish
bin/console quire:content:delete <id>
bin/console quire:content:restore <id>
```

`--body-file=-` reads the body from standard input. `--author` takes a user's ID, email or username; on `quire:content:update`, `--author ""` leaves the item without an author. Each command's `--help` lists its options.

### Slugs

The slug is the part of the URL that names an item, e.g. `hello-world`. Quire generates it from the title, transliterated for the item's language (`Über uns` becomes `ueber-uns` in German), and adds a number when a sibling already uses it (`hello-world-2`). Pass `--slug` to choose one yourself: lowercase letters and digits separated by hyphens.

Changing the title doesn't change the slug, so published links keep working. Change the slug explicitly when you want a new URL: the old one then redirects to it (see [URLs](#urls)).

### Statuses

Every new item is a **draft**. Transitions move it between statuses:

| Transition | From | To |
|------------|------|----|
| `publish` | draft, scheduled, archived | published |
| `schedule` | draft, scheduled | scheduled (needs `--at` with a future date) |
| `unpublish` | published, scheduled, archived | draft |
| `archive` | published | archived |

```bash
bin/console quire:content:transition <id> schedule --at "2026-12-24 09:00"
bin/console quire:content:transition <id>          # lists the transitions possible now
```

Scheduled items become public on their date by themselves. What extensions do when content is published (feeds, notifications, …) runs within a minute: the [scheduled task](jobs.md#scheduled-tasks) `quire.publish-due` publishes them. `bin/console quire:content:publish-due` does the same at once.

Extensions can add statuses, such as an editorial review step, and conditions for transitions.

### Revisions

Each time a post's or page's title, excerpt, body or fields change, Quire keeps a revision. Changes that don't touch the text, such as moving a page, don't create one.

```bash
bin/console quire:content:revisions <id>
bin/console quire:content:revisions <id> --restore <revision-id>
```

Restoring a revision saves its text as a new revision, so the history is never rewritten. The `quire.content.revisions_keep` setting (default `50`) sets how many revisions each item keeps.

### Pending revisions

Editing a published item shouldn't publish every keystroke. Admin interfaces therefore save changes to published content as a **pending revision**: a working copy of the item's text and fields, saved as you type, that visitors don't see until someone publishes it. Publishing saves a revision as usual. Saving the item directly, as `quire:content:update` does, still changes it at once.

A pending revision remembers the version of the item it started from. When it's published, Quire merges it with whatever was published since, the way version control systems merge: each field separately, and the text line by line. One editor can fix a typo in the first paragraph while another rewrites the last, and neither loses their work. Only changes to the same lines or fields **conflict**. Publishing then stops, the item stays as it is, and the conflicting parts are listed. Resolve them in the pending revision and publish again. An item can have several pending revisions, e.g. one per editor.

Changes can be **reviewed** before they're published. The author asks for a review; a reviewer sees the diff of the text, then approves it, asks for changes, or comments. Changes saved after an approval need approving again, and authors can't approve their own changes. Review is optional, unless the `quire.content.changes_need_approval` setting is on: then only approved changes can be published, and the revision records who approved them. Who may review is the `review` ability of each content type (see [permissions](permissions.md)).

```bash
bin/console quire:content:pending <id>                         # the item's pending revisions
bin/console quire:content:pending <id> --show <pending-id>     # what it changes, as a diff, and whether it merges
bin/console quire:content:pending <id> --submit <pending-id>   # ask for a review
bin/console quire:content:pending <id> --approve <pending-id> --as kim
bin/console quire:content:pending <id> --request-changes <pending-id> --message "Add a source." --as kim
bin/console quire:content:pending <id> --comment <pending-id> --message "Looks good so far." --as kim
bin/console quire:content:pending <id> --publish <pending-id>
bin/console quire:content:pending <id> --discard <pending-id>
bin/console quire:content:pending <id> --schedule <pending-id> --at "2026-12-24 09:00 Europe/Berlin"
```

**Comments** discuss changes before they're published: their author, reviewers and those who may edit the item write them, and reply to them. A comment can point at text of the changes, e.g. a name in the third paragraph. As the changes are edited, Quire finds that text again near where it was, so the comment moves with it. When the text is gone, the comment is *outdated*, and still shows what it quoted. Whoever started a thread, the changes' author or a reviewer marks it resolved. Comments go with the changes when they're published or discarded; the [audit log](audit.md) keeps that they were made. In code, they're `Quire\Content\Revision\PendingRevisionComments`.

```bash
bin/console quire:content:comments <id> <pending-id>                                   # the threads, and where they point now
bin/console quire:content:comments <id> <pending-id> --add "Full name?" --quote "Northside" --line 12 --as kim
bin/console quire:content:comments <id> <pending-id> --add "Yes, since August." --reply-to <comment-id>
bin/console quire:content:comments <id> <pending-id> --resolve <comment-id>
```

**Scheduled changes** are published at their time by the background worker (the task `quire.publish-due`), with the same merging, conflicts and approval as publishing them by hand. When that fails, they stay pending without a time, their `publishProblem` says why, and Quire dispatches `PendingRevisionPublishFailed`, e.g. for an extension that emails their author. `--at none` clears the time.

### Checks before publishing

Content is checked before it's published: images need a description (alt text), links to pages of the site must lead somewhere visitors can go, items of types with excerpts need one, and titles should fit in search results. Extensions add their own checks. The admin shows what they find as you write.

Each check has a level: an **error** blocks publishing (and scheduling, publishing changes, and saving a published item), a **warning** doesn't. Core's checks are warnings; a site makes them errors, or turns them off, with the setting `quire.content.check_levels`:

```bash
bin/console quire:settings:set quire.content.check_levels '{"core.alt-text": "error", "core.excerpt": "off"}'
bin/console quire:content:check --list          # the checks and their levels
bin/console quire:content:check                 # check published content, e.g. in CI or every night
bin/console quire:content:check --strict        # warnings fail it too
```

`quire:content:check` exits with 1 when it finds an error, so a scheduled job or a CI step notices, for example when a page that others link to was unpublished. Drafts may have errors: they're work in progress.

What the checks find is also **stored** for each item, so lists show it and filter by it without checking every item they show: `$query->withIssues()` finds items with something to look at, `withIssues(Level::Error)` those that can't be published, `withIssues(check: 'core.alt-text')` those of one check, and `Quire\Content\Check\CheckResults` reads the findings of a page of items in one query. An item is checked when it's saved; the items that link to it when visitors start or stop seeing it, or its URL changes; all items when the site turns a check on; and every night (the scheduled task `quire.recheck-content`). The site's levels apply when findings are read, so changing a level takes effect at once. After upgrading a site, `bin/console quire:content:check --store` checks every item right away instead of the next night.

### Editing conflicts

Each save increases the item's **version**. Editing tools send the version they started from, and if someone else saved the item in the meantime, the save fails instead of overwriting the other person's work. On the command line, pass `--expect-version` to get the same protection.

### Trash

`quire:content:delete` moves an item to the trash: visitors no longer see it, and its slug is free for other items. `quire:content:restore` brings it back; if its slug was taken meanwhile, it gets a numbered one. `quire:content:delete --purge` deletes an item permanently, together with its revisions, pending revisions and the data extensions keep about it. Pending revisions of an item in the trash wait for it to be restored.

A page with child pages can't be moved to the trash or deleted. Move or delete its children first.

### Translations

Pass `--translation-of <id>` and `--locale` to create a translation of an item. Each item can have one translation per language. New items use the site language (the `site.locale` setting) unless you pass `--locale`.

## URLs

Each content type's items get URLs from a **permalink pattern**, a site setting you can change without touching code:

```bash
bin/console quire:permalink:list
```

```
 ------ -------------- ------
  Type   Items          List
 ------ -------------- ------
  post   /blog/{slug}   /
  page   /{path}        –
 ------ -------------- ------

 Homepage: the list of posts
```

Patterns combine static text with placeholders:

| Placeholder | Value |
|-------------|-------|
| `{slug}` | The item's slug, e.g. `hello-world` |
| `{path}` | For hierarchical types, the slugs of the item's ancestors and its own: `about/team` |
| `{id}` | The item's ID |
| `{year}`, `{month}`, `{day}` | The publication date, e.g. `2026`, `10`, `22` |

Every pattern needs `{slug}`, `{path}` or `{id}`. To use dates in post URLs, the way many WordPress sites do:

```bash
bin/console quire:settings:set quire.permalinks.post "/{year}/{month}/{slug}"
```

Changes apply right away, with no cache to clear. Two types can't have patterns that produce the same URLs (`/blog/{slug}` and `/blog/{id}`): saving such a pattern fails and names the other type. Patterns that only overlap, such as `/{slug}` for posts and `/{path}` for pages, are allowed. The more specific one is tried first, in the order `quire:permalink:list` shows.

Only items visitors may see have a working URL: drafts, scheduled items that aren't due and items in the trash respond with "404 Not Found". Users who may preview unpublished items see drafts at their URL (see [permissions](permissions.md#abilities-of-content)).

Every other route of the site matches before content URLs: the application's, those of extensions, and Quire's own, such as `/login`. So an item can't have a URL that one of them takes: saving it fails and names the route, e.g. "The URL /login is taken by a page of the site itself (the route "quire_login"). Choose another slug."

### Old URLs keep working

When a URL changes, the old one redirects to the new one with "301 Moved Permanently". You don't need to add these redirects yourself:

- **A changed slug** (`/blog/hello` → `/blog/hi`). Quire remembers every path an item had, so even its first URL leads straight to the current one, never through a chain of redirects.
- **A moved or renamed page.** Its child pages move along, and their old URLs redirect too.
- **A changed pattern.** URLs in the type's earlier patterns keep working.

Other spellings of a URL also redirect to the right one: a trailing slash, capital letters, or a date that doesn't match the item's (`/2024/01/hello` when it was published in 2025).

### Lists and the homepage

Listable types such as `post` have a list of their items, newest first by default. Its URL is the setting `quire.listings.<type>`, e.g. `/blog`, and pages after the first add `?page=2`. Set it to an empty value for no list. Lists show the items in the site's language (`site.locale`), and the number of items per page is the setting `quire.content.per_page`.

The homepage shows one of these, set with `site.homepage`:

| `site.homepage` | Shows |
|-----------------|-------|
| `listing` (default) | The list of the type in `site.homepage_listing`, `post` by default. Its own URL (`/blog`) then redirects to the homepage. |
| `page` | The page in `site.homepage_page`, given by ID or path, e.g. `home`. The page's own URL then redirects to the homepage. |
| `route` | The page of an extension or of the application: the route name in `site.homepage_route`. The route must not need parameters. |

```bash
bin/console quire:settings:set site.homepage_page home
bin/console quire:settings:set site.homepage page
```

### Redirects

To keep the URLs of a previous site working, add redirects by hand:

```bash
bin/console quire:redirect:add /2019/05/hello-world /blog/hello-world
bin/console quire:redirect:add /shop https://shop.example.com --status=302
bin/console quire:redirect:list
bin/console quire:redirect:remove /shop
```

When the target is the URL of a content item, the redirect points at the item, so it still works after the item's URL changes. Redirects never hide content: one only applies when nothing is published at its URL. Old paths are matched without their trailing slash and capital letters, so `/2019/05/Hello-World/` and `/2019/05/hello-world` are the same.

To add many at once, for example when moving from WordPress, import a CSV file with the old and new URL on each line, and optionally the status:

```
source,target
https://example.com/2019/05/hello-world/,/blog/hello-world
https://example.com/about-us/,/about
```

```bash
bin/console quire:redirect:import redirects.csv
```

Lines that can't be added are reported, and the others are still added.

### Templates

Items render with the first of these templates that exists, so a theme or site can override them for one item, one type or all types:

1. `templates/content/<type>/<slug>.html.twig`
2. `templates/content/<type>/single.html.twig`
3. `templates/content/single.html.twig`
4. Quire's own `@Quire/content/single.html.twig`

Lists use `templates/content/<type>/list.html.twig`, then `templates/content/list.html.twig`. Each of Quire's own templates lists the variables it receives at the top. `templates/` here means the site's directory, then the active theme's (see [themes](themes.md#where-templates-come-from)).

Templates build links with these functions:

| Function | Returns |
|----------|---------|
| `quire_url(item)` | The item's URL. `quire_url(item, true)` includes the domain. |
| `quire_listing_url('post')` | The URL of a type's list; `quire_listing_url('post', 2)` for its second page |
| `quire_home_url()` | The homepage's URL |
| `quire_body(item)` | The item's body as HTML |
| `quire_excerpt(item)` | A plain-text summary; `quire_excerpt(item, 120)` for at most 120 characters |

In PHP, `Quire\Content\Permalink\Permalinks` has the same: `url($item)`, `listingUrl('post')` and `homeUrl()`.

### Adding Quire to an existing application

When Quire runs inside another Symfony application, put its URLs under a path of their own in `config/packages/quire.yaml`:

```yaml
quire:
    routing:
        prefix: /cms
```

The homepage is then `/cms` and posts are at `/cms/blog/…`. Wherever Quire is mounted, its URLs only answer when no other route does, so the application's routes and extensions' routes always win. The admin interface has a path of its own, `/admin` by default; change it with `admin_prefix` (see [admin](admin.md#its-address)).

## Querying content

Templates and code list content with the **content query builder**. In Twig:

```twig
{% for post in quire_content('post').latest.limit(5) %}
    <a href="{{ quire_url(post) }}">{{ post.title }}</a>
{% endfor %}
```

In PHP, inject `Quire\Content\ContentRepository`:

```php
$posts = $content->query('post')
    ->where('fields.rating', '>=', 4)
    ->orderBy('fields.rating', 'desc')
    ->paginate(page: 2, perPage: 10);
```

Each method returns a new query, so a query can be stored and refined without changing the original. Nothing is read from the database until the query is iterated or you call `get()`, `first()`, `count()` or `paginate()`.

### What queries return

Queries only return what visitors may see: items that are **published** (or scheduled, once their date has passed), that aren't in the **trash**, and that no extension's **visibility rule** hides (for example, unapproved reviews). This applies everywhere, so a template can't leak drafts by forgetting a condition.

Code that shows content to editors, such as admin screens and the command line, opts out explicitly:

```php
$content->query('post')->withoutVisibilityScope();            // drafts, archived, scheduled, hidden
$content->query('post')->withoutVisibilityScope()->onlyTrashed(); // the trash
```

By ID, `find($id)` and `get($id)` return an item whatever its status, and `findMany($ids)` returns many in one query, by ID (unknown IDs are left out): use it for a list of things that each name an item, instead of a query per row.

Templates can't opt out: `withoutVisibilityScope()`, `withTrashed()` and raw SQL aren't available in them.

### Filtering and sorting

| Method | Example |
|--------|---------|
| `where($field, $value)` / `where($field, $operator, $value)` | `where('fields.featured', true)`, `where('fields.rating', '>=', 4)`, `where('fields.color', 'in', ['red', 'blue'])`, `where('fields.tags', 'contains', 'php')` |
| `status(...)`, `locale($locale)`, `childrenOf($parent)` | `childrenOf(null)` lists top-level pages |
| `scope($name, ...$arguments)` | A filter an extension registered, e.g. `scope('featured')` |
| `orderBy($field, 'asc'\|'desc')`, `latest()`, `oldest()` | `orderBy('fields.rating', 'desc')` |
| `limit($n)`, `offset($n)` | |

Operators are `=`, `!=`, `<`, `<=`, `>`, `>=`, `in`, `not in`, and for list fields `contains` (has all of the values) and `contains any`. Comparing with `null` finds items without a value.

Queries can sort by `published_at`, `created_at`, `updated_at`, `title` and `position`, and filter by `id`, `slug`, `locale`, `status`, `parent_id`, `author_id`, `translation_group_id`, `published_at`, `created_at` and `updated_at`. Both work with fields declared `sortable` or `filterable`, written as `fields.<name>`. Those fields have a database index, which the query uses, so listings stay fast as the site grows. Using any other field is an error that names the fields you can use. Values are checked against the field's type: `where('fields.rating', 'five')` fails with a clear message instead of matching nothing.

Without `orderBy()`, a query uses its type's default order (newest first for posts, by position for pages). Items without a value for the sort field come last. Items that sort the same are ordered by ID, so pages never repeat or skip an item.

### Search

`search($terms)` finds the items whose title, excerpt or body contain the words, best matches first:

```twig
{% set results = quire_content('post', 'page').search(app.request.query.get('q', ''), snippets: true).paginate(app.request.query.getInt('page', 1), 10) %}
{% for item in results %}
  <h2><a href="{{ quire_url(item) }}">{{ item.title }}</a></h2>
  <p>{{ item.search_snippet }}</p>
{% endfor %}
```

- **Words are matched in the item's language,** so `running` finds `run` in English items, and common words such as `the` are left out. Titles match their words as they're written too, so a page called "About" is found by `about`. Items in languages PostgreSQL has no rules for match the words as they are.
- **The syntax is the one people know from search engines:** all words must match, `"site search"` is a phrase, `-plugin` leaves out items with the word, and `or` between words matches either.
- **`prefix: true`** matches words that start with the last one, for search as you type: `search('mark', prefix: true)` finds "Markdown". Then the terms are words only.
- **Ranking:** a word in the title counts most, then in the excerpt, then in the body. Each item has `search_rank`, from 0 to 1. `orderBy()` replaces the ranking, e.g. `search('php').latest()`, and `orderBy('search_rank')` uses it.
- **`snippets: true`** gives each item `search_snippet`: one or two passages of the excerpt and body with the words in `<mark>`, as safe HTML without Markdown's markup. Snippets take one more query, for the items returned only.
- **Visibility applies as everywhere,** so search never shows a visitor a draft or a members-only item. Blank terms don't filter.

On the command line: `bin/console quire:content:list --search "markdown -draft"`.

### Pagination

`paginate($page, $perPage)` returns one page, with the total number of matching items:

```twig
{% set posts = quire_content('post').paginate(app.request.query.getInt('page', 1), 10) %}
{% for post in posts %}…{% endfor %}
<p>Page {{ posts.page }} of {{ posts.pageCount }}</p>
{% if posts.hasNextPage %}<a href="?page={{ posts.page + 1 }}">Older posts</a>{% endif %}
```

Page numbers below 1 count as the first page, so a number from the URL can be passed as is. `isOutOfRange()` tells you when a page is past the last one, so the listing can respond with 404.

In templates, queries return 10 items unless you set a limit, and at most 100 at once (`limit()` and `paginate()` refuse more). An accidental `quire_content('post')` therefore never loads the whole site.

### Counting

`countBy()` counts the items a query finds by one of their properties, in one database query: the numbers next to the filters of a list (facets), or an archive by month.

```twig
{% for month, count in quire_content('post').countBy('published_at', per: 'month') %}
    <a href="/blog/{{ month }}">{{ month }}</a> ({{ count }})
{% endfor %}
```

```php
$posts->countBy('status');                        // ['published' => 40, 'draft' => 10]
$posts->countBy('fields.tags');                   // a list field: each value, e.g. ['php' => 31, 'cms' => 12]
$posts->countBy('fields.topics');                 // a relation: by the linked item's ID
$posts->countBy('published_at', per: 'week');     // ['2026-W43' => 5, …], in the site's time zone
$posts->countBy('checks');                        // by check, items with findings (see checks below)
```

You can count by `type`, `status`, `locale`, `author_id`, `parent_id`; by `published_at`, `created_at` and `updated_at` per `year`, `month`, `week` or `day`; by a filterable field; and by `checks`. The most frequent values come first (for dates, the latest), at most 100 unless you pass a `limit`. Items without a value aren't counted. Like everything a query does, only what visitors may see is counted unless the query opts out.

A filter's own numbers usually ignore that filter, so people see what else they could choose: `withoutWhere()` takes a field's filters off, e.g. `$drafts->withoutWhere('status')->countBy('status')` still counts the published items that the search finds.

### Running a query later

A query can be written down as plain values, e.g. to act on every item a list finds in a background job instead of during the request:

```php
$job = new ArchiveAll($query->toArray());            // types, and the methods that made the query
// …later, in the job's handler:
foreach ($content->queryFromArray($job->query)->get() as $item) { … }
```

It holds what your code wrote, including SQL from `whereSql()`, so keep it on the server: never make a query from what a visitor sends.

### Related content

Load relations for all items of a query at once with `with()`. Core provides `parent`, `children` (by position) and `translations` (by language); extensions add their own, such as comments:

```twig
{% for page in quire_content('page').childrenOf(null).with('children') %}
    <li>{{ page.title }}
        <ul>{% for child in page.children %}<li>{{ child.title }}</li>{% endfor %}</ul>
    </li>
{% endfor %}
```

Each relation costs one database query per page of results, however many items the page shows. Related items follow the same visibility rules: visitors don't see draft children. In PHP, read a relation with `$page->attribute('children')`. Reading one that wasn't loaded never runs a query per item: PHP code gets an error that tells you to add `with()`, and so do templates in development.

### Relation fields

A `relation` field links an item to other items, e.g. a post to its tags, or a page to related pages:

```php
Extend\ContentType::existing('post')
    ->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'posts'])
    ->field('series', 'relation', options: ['types' => ['series'], 'multiple' => false]),
```

| Option | |
|--------|-|
| `types` | The content types it links to (required) |
| `multiple` | A list of items, in order (`true`, the default), or one item (`false`) |
| `limit` | The most items of a list |
| `inverse` | The name of the relation on the linked types that lists the items linking to them, e.g. a tag's `posts` |

Its value is the IDs of the linked items: `$post->field('tags')` is a list of IDs. Set it with items or IDs (`fields: ['tags' => [$php, $symfony]]`). Saving checks that the items exist, aren't in the trash, are of the right types, and aren't the item itself.

- **Load the linked items** with `with()`, under the same visibility rules as everything else, so a visitor never sees a draft tag: `quire_content('post').with('tags')`, then `{% for tag in post.tags %}`. The inverse works the same way: `quire_content('tag').with('posts')`. For a long list, e.g. a tag's page with hundreds of posts, query and paginate it instead (below).
- **Filter by links:** lists with `contains` (links to all the items given) and `contains any`, single relations with `=`, `!=`, `in` and `not in`. Comparing with `null` finds items without a link.

  ```twig
  {% set posts = quire_content('post').where('fields.tags', 'contains', tag).paginate(page, 10) %}
  ```

- **The database keeps links consistent.** Links are also kept in the table `quire.content_relation`, with foreign keys, which filters use. Deleting an item for good removes the links to it, from the table and from the items' fields. Restoring a revision drops links to items deleted since.
- Relations can't be sorted by, and have no default value.

## How content is stored

All content lives in PostgreSQL, in one `quire.content` table, together with everything else about the site, so one database backup contains all of it (uploaded files are on [storage disks](storage.md)). See [docs/database.md](database.md).

- **IDs** are time-ordered UUIDs (version 7), so they sort by creation time and are safe to show in URLs.
- **Slugs** are unique among items of the same type, parent and language. Items in the trash don't hold on to their slug.
- **Languages:** every item has a `locale`, such as `en` or `pt-BR`. Translations of the same item share a `translation_group_id`, with at most one translation per language.
- **Search:** each item's title, excerpt and body are indexed for full-text search in the item's language. The database maintains the index itself, so it never goes stale.
- **History:** revisions are stored in `quire.content_revision` and deleted together with their item.
- **Integrity:** a page that still has child pages can't be deleted. Its children first need to be moved or deleted.
