# Changelog

Every release of Quire, newest first: what it brings, the security fixes, and what to do when updating. Each release's date is on its [GitHub release](https://github.com/quirecms/framework/releases).

<!-- Made from docs/releases/<version>.md by "php .github/feed.php --changelog > CHANGELOG.md": change those files, not this one. A test checks it's up to date. -->

## [0.2.1](https://github.com/quirecms/framework/releases/tag/v0.2.1)

Nobody finds out who has an account by trying to sign in or register.

- **Signing in takes the same time whether the account exists or not:** the password typed for an account that doesn't exist, or has no password (single sign-on only), is checked against a dummy hash made with the site's own settings, as Spring Security and Django do. Before, those answered without hashing, so a wrong password for a real account took visibly longer.
- **Registering does the same work for a new and a taken address:** the password is hashed for both, and both answer "Check your inbox".
- **Registration needs email confirmation:** it's open only while both settings are on, so the form never says an address is taken. The admin's settings say so.

### Upgrading

A patch release: sites on `^0.2` take it with their next update, with no new migrations and no change to the skeleton's files.
- Sites with registration on (`quire.auth.registration`) and email confirmation off (`quire.auth.email_verification`): registration closes, and the sign-in page no longer links to it, until confirmation is turned on (`bin/console quire:settings:set quire.auth.email_verification true`). Without confirmation, the form had to say whether an address already had an account.

## [0.2.0](https://github.com/quirecms/framework/releases/tag/v0.2.0)

People editing together keep each other's changes, nobody can give out more than they hold, and a backup says when it lacks a file.

- **Two people editing one item:** changes are started from the version their author opened, and publishing merges them with what was published meanwhile, so both people's changes stay. The editor says when the live item changed since you started, and two tabs can't make two sets of changes. See "Changing published content" in docs/admin.md, and "Pending revisions" in docs/content.md.
- **Nobody acts beyond what they hold:** a role, an ability or a user is managed only by someone who holds everything it carries, so a user manager can't make themselves admin and a permission manager can't grant what they don't have. Settings that change who may do what name the ability they take. See docs/permissions.md.
- **"Confirm it's you":** the password again before changing someone else, roles, grants, extensions or the theme, for 10 minutes. Admins email a link to choose a new password instead of setting one, and changing someone's email address tells the old one.
- **Rules see the item:** every check about an item passes the item, so a rule such as "nobody edits a locked item" decides everywhere: the admin's editor, lists, bulk actions and history, and the command line. "View as" follows rules too, and is checked on every request.
- **Backups have every stored file, or say so:** a backup reaches disabled extensions' disks too, and one that lacks a file (missing, damaged, or on a disk nothing declares) is still written, says so in its manifest with why for each file, exits with 3 and is logged as failed. `--allow-incomplete` accepts it; restoring says so before it starts. The garbage collector keeps the files of disabled extensions. See docs/backups.md.
- **Server contract 2:** `quire:backup`'s exit codes and its manifest's `complete` and `absent` are part of the contract tools like Quire Server rely on. See "Tools that manage sites" in docs/docker.md.
- **The release feed vouches for the code:** each release names every package's commit, so a moved tag or other code served for a version is refused before it's built. Every workflow's actions are pinned by commit.

Fixes:
- A site's PostgreSQL comes back after a reboot, like the site that needs it.
- A site's build no longer waits for every disk of the machine to flush (`sync`): one slow mount stalled every build.
- `quire:about` is made even when the storage disks can't be checked; its warnings say so instead.
- The last backup is the last one recorded, even when the machine's clock was set back in between.
- A disk the site placed for a disabled extension no longer fails the build: it's kept for backups, not served.
- Symfony 8.1.11, with the fixes for the advisories published on 2026-11-04; CI audits the lock for advisories.

### Upgrading

A new minor version: sites require `^0.1`, so they move to it only when told, and there are no new migrations.
- Sites run by Quire Server: update the server first (`quire-server update`; it needs a release that keeps server contract 2, and an older one refuses 0.2.0 and says so), then `quire-server site:update <site> --to=0.2.0`. The server's nightly updates stay on 0.1.
- Other sites: `composer require "quire/core:^0.2" "quire/admin:^0.2" "quire/theme-default:^0.2"` (and `composer require --dev "quire/testing:^0.2"` where it's used), then take the skeleton's new `compose.yaml` (PostgreSQL restarts by itself) and `Dockerfile` from https://github.com/quirecms/skeleton/tree/v0.2.0, and build again.
- Extensions and themes: require `"quire/core": "^0.2"`.

What changes for the people and scripts that use a site:
- `quire:backup` exits with 3 for a backup that's written but lacks stored files, where it exited with 0. A script that treats any other exit code as a failure now sees one; `--allow-incomplete` accepts it for a run.
- Admins no longer choose other people's passwords: they email them a link to choose one. Changing someone else, roles, grants, extensions or the theme asks for your password again ("Confirm it's you", for 10 minutes); someone without a password signs in again.
- Settings that change who may do what (grants, roles) or run code (the theme) take their own ability, not only the settings one, and an import of settings is all or nothing.

For extensions: permission rules now get the item in every question about one (editing, publishing, reviewing, deleting), as `Quire\Content\Action\ContentAccess` asks them. A rule that decided without it still works; one that refused when it had no item now sees one. Extensions' list actions are offered only on items the user may edit.

## [0.1.2](https://github.com/quirecms/framework/releases/tag/v0.1.2)

Sites build on Quire's runtime images, and each release comes with a signed feed.

- **Quire's runtime images:** a site's `Dockerfile` now builds on `ghcr.io/quirecms/runtime:8.5` (and `8.5-builder`): PHP 8.5 with Quire's extensions, FrankenPHP and the PostgreSQL tools, rebuilt every week with PHP's, FrankenPHP's and Debian's security fixes, and signed. A site gets those fixes at its next build, without waiting for a Quire release, and no longer compiles PHP's extensions itself. Quire Server checks their signature, and builds its sites again on a newer one every night. See "The runtime images" in docs/docker.md.
- **The release feed:** every release publishes its notes, the security fixes it brings, what to do when updating and what it requires, signed, for tools that update sites (Quire Server reads it before an update changes a site's Quire).
- **The skeleton is built from the application** of Quire's repository: new sites get exactly the files Quire is developed and tested with.

### Upgrading

Sites run by Quire Server get the new `Dockerfile` with this update, unless they edited theirs (then the update shows the difference to merge). Other sites can take it from the skeleton (https://github.com/quirecms/skeleton/blob/v0.1.2/Dockerfile) and build again with `docker compose --env-file prod.env build --pull`; their own Caddyfile, PHP settings and entry point don't change.

## [0.1.1](https://github.com/quirecms/framework/releases/tag/v0.1.1)

Quire sites can now be managed by Quire Server, and run behind another web server.

- **The server contract (version 1):** `quire:about` says what a site runs (its Quire, its migrations, its contract), and every site's Compose files carry labels a tool can find it by. See "Tools that manage sites" in docs/docker.md.
- **Installing with an invitation:** `quire:install --invite` emails the first admin a link to choose their password, instead of showing one.
- **Behind a proxy:** a site can run behind another web server or load balancer (`compose.proxy.yaml`), trusting its forwarded headers only from it.

### Upgrading

Nothing to do: sites update with `composer update "quire/*"`. To install with an invitation or run behind a proxy, see docs/docker.md.

## [0.1.0](https://github.com/quirecms/framework/releases/tag/v0.1.0)

The first release of Quire, the extensible CMS for PHP, built on Symfony.

- **Content:** content types with typed fields, revisions, drafts and scheduled changes, reviews with comments, relations, full-text search, and URLs that never break old links.
- **Extensions and themes:** extensions add content types, fields, settings, pages, checks and assets; themes inherit from each other and can be previewed before they're used.
- **People:** accounts, roles and permissions, invitations, and registration, email confirmation and password reset (off by default).
- **The admin:** an editor written like a manuscript, lists with filters, a board and a calendar, settings as sentences, activity with undo, and keyboard and phone use throughout.
- **Running a site:** background jobs, storage on a volume or S3, backups of the database and files in one file, and a production setup with Docker (`compose.prod.yaml`, secrets in `prod.env`, security headers by default).

Start a site with `composer create-project quire/skeleton my-site` (see docs/docker.md).
