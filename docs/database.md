# Database

Quire stores everything in PostgreSQL: content, revisions, settings, which extensions are enabled, the data of every extension, and the record of every uploaded file (the files themselves are on [storage disks](storage.md)). All of it lives in a PostgreSQL schema of its own, named `quire`:

| Table | Contents |
|-------|----------|
| `quire.content` | Every content item: posts, pages and the types extensions add |
| `quire.content_revision` | Earlier versions of content items |
| `quire.content_path_history` | Paths content items had before, so their old URLs redirect |
| `quire.permalink_history` | URL patterns content types had before |
| `quire.redirect` | Redirects added by hand |
| `quire.app_user` | Users |
| `quire.user_identity` | Identities that link users to other sign-in providers, e.g. single sign-on |
| `quire.session` | Sessions of signed-in users |
| `quire.setting` | Site settings changed from their defaults |
| `quire.extension` | Which extensions are enabled |
| `quire.migration_versions` | Which of Quire's migrations have run |
| `quire.<extension>_*` | Extensions' own tables, e.g. `quire.acme_reviews_review` |

Quire never creates or changes anything outside its schema. Your application's tables can sit in the same database, in the `public` schema or any other, even with the same names as Quire's.

## Migrations

Quire core and extensions change the database with migrations. They're separate from your application's own migrations, so each side keeps its own history:

| Command | Runs |
|---------|------|
| `bin/console quire:migrations:migrate` | Pending migrations of Quire core and the enabled extensions |
| `bin/console doctrine:migrations:migrate` | Your application's own migrations, if it has any |

The Docker image runs both when it starts, and enabling an extension runs Quire's. To see what has run, use `bin/console quire:migrations:status` or `quire:migrations:list`.

Quire's migrations run in dependency order: core first, then extensions in the order they boot, so an extension's migrations can rely on the tables of the extensions it requires.

## Sharing a database with an application

Because everything Quire stores is in its own schema, Quire can use the database of an existing application:

- **No clashes.** Your application can have its own `content`, `setting` or `extension` tables.
- **Schema tools leave Quire alone.** `doctrine:schema:update` and `doctrine:migrations:diff` don't see the `quire` schema, so they never generate SQL that changes or drops Quire's tables.
- **Separate backups.** `bin/console quire:backup` saves Quire's data, including its migration history, without your application's data, and `quire:restore` puts it back without touching your application's tables. See [backups](backups.md).

Quire connects with the application's default Doctrine connection, so its writes can take part in the application's transactions.

## Writing SQL

Always write the schema in table names, in queries and in migrations: `SELECT … FROM quire.content`, `CREATE TABLE quire.acme_reviews_review (…)`. Quire doesn't rely on PostgreSQL's `search_path`, which connection poolers such as PgBouncer don't keep between transactions. In PHP, the schema's name is available as `Quire\Database\DatabaseSchema::NAME`.
