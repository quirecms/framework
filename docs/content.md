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

Content types and extensions can declare **fields**: typed values such as a product's price, an event's date or a post's rating. To see a type's fields and their rules:

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
bin/console quire:content:create page "Team" --parent <page-id> --body-file team.md
bin/console quire:content:list --type post
bin/console quire:content:show <id>
bin/console quire:content:update <id> --title "Hello again" --message "Better title"
bin/console quire:content:transition <id> unpublish
bin/console quire:content:delete <id>
bin/console quire:content:restore <id>
```

`--body-file=-` reads the body from standard input. Each command's `--help` lists its options.

### Slugs

The slug is the part of the URL that names an item, e.g. `hello-world`. Quire generates it from the title, transliterated for the item's language (`Über uns` becomes `ueber-uns` in German), and adds a number when a sibling already uses it (`hello-world-2`). Pass `--slug` to choose one yourself: lowercase letters and digits separated by hyphens.

Changing the title doesn't change the slug, so published links keep working. Change the slug explicitly when you want a new URL.

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

Scheduled items become public on their date by themselves. To also run what extensions do when content is published (feeds, notifications, …), run `bin/console quire:content:publish-due` every minute or so, e.g. from cron. Several servers can run it at the same time.

Extensions can add statuses, such as an editorial review step, and conditions for transitions.

### Revisions

Each time a post's or page's title, excerpt, body or fields change, Quire keeps a revision. Changes that don't touch the text, such as moving a page, don't create one.

```bash
bin/console quire:content:revisions <id>
bin/console quire:content:revisions <id> --restore <revision-id>
```

Restoring a revision saves its text as a new revision, so the history is never rewritten. The `quire.content.revisions_keep` setting (default `50`) sets how many revisions each item keeps.

### Editing conflicts

Each save increases the item's **version**. Editing tools send the version they started from, and if someone else saved the item in the meantime, the save fails instead of overwriting the other person's work. On the command line, pass `--expect-version` to get the same protection.

### Trash

`quire:content:delete` moves an item to the trash: visitors no longer see it, and its slug is free for other items. `quire:content:restore` brings it back; if its slug was taken meanwhile, it gets a numbered one. `quire:content:delete --purge` deletes an item permanently, together with its revisions and the data extensions keep about it.

A page with child pages can't be moved to the trash or deleted. Move or delete its children first.

### Translations

Pass `--translation-of <id>` and `--locale` to create a translation of an item. Each item can have one translation per language. New items use the site language (the `site.locale` setting) unless you pass `--locale`.

## Querying content

Templates and code list content with the **content query builder**. In Twig:

```twig
{% for post in quire_content('post').latest.limit(5) %}
    <a href="…">{{ post.title }}</a>
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

## How content is stored

All content lives in PostgreSQL, in one `content` table, together with everything else about the site, so one database backup contains the whole site.

- **IDs** are time-ordered UUIDs (version 7), so they sort by creation time and are safe to show in URLs.
- **Slugs** are unique among items of the same type, parent and language. Items in the trash don't hold on to their slug.
- **Languages:** every item has a `locale`, such as `en` or `pt-BR`. Translations of the same item share a `translation_group_id`, with at most one translation per language.
- **Search:** each item's title, excerpt and body are indexed for full-text search in the item's language. The database maintains the index itself, so it never goes stale.
- **History:** revisions are stored in `content_revision` and deleted together with their item.
- **Integrity:** a page that still has child pages can't be deleted. Its children first need to be moved or deleted.
