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

## How content is stored

All content lives in PostgreSQL, in one `content` table, together with everything else about the site, so one database backup contains the whole site.

- **IDs** are time-ordered UUIDs (version 7), so they sort by creation time and are safe to show in URLs.
- **Slugs** are unique among items of the same type, parent and language. Items in the trash don't hold on to their slug.
- **Languages:** every item has a `locale`, such as `en` or `pt-BR`. Translations of the same item share a `translation_group_id`, with at most one translation per language.
- **Search:** each item's title, excerpt and body are indexed for full-text search in the item's language. The database maintains the index itself, so it never goes stale.
- **History:** revisions are stored in `content_revision` and deleted together with their item.
- **Integrity:** a page that still has child pages can't be deleted. Its children first need to be moved or deleted.
