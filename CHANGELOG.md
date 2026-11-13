# Changelog

Every release of Quire, newest first: what it brings, the security fixes, and what to do when updating. Each release's date is on its [GitHub release](https://github.com/quirecms/framework/releases).

<!-- Made from docs/releases/<version>.md by "php .github/feed.php --changelog > CHANGELOG.md": change those files, not this one. A test checks it's up to date. -->

## [0.3.0](https://github.com/quirecms/framework/releases/tag/v0.3.0)

Visitors are asked before another company's service runs, Google Analytics comes in that way, and owners see how their site is set up.

- **Privacy and cookies (`quire/privacy`, new):** extensions and themes declare the services of other companies they use (analytics, videos, fonts), and visitors are asked about them in a banner and a settings dialog: accept and reject alike, nothing on in advance, Global Privacy Control and Do Not Track respected, asked again only about what changed. A service's script runs only once it's allowed. Each choice is kept as proof, with what the visitor was told, for as long as the owner sets. Embeds of trusted shortcodes (a video player) wait in a placeholder until the visitor loads them once or allows the service. `[[privacy_services]]` lists the services in the site's privacy notice. The owner sets it all in the admin, under "Privacy and cookies", which also previews the banner in the site's look and looks a visitor's choice up by its ID. See docs/privacy.md.
- **Analytics (`quire/analytics`, new):** Google Analytics 4, turned on by its measurement ID in the admin's settings, loaded only once visitors allow analytics (Consent Mode v2, advertising off), without tokens or e-mail addresses in the addresses it sends, and never on sign-in pages, previews or error pages. Templates mark events in plain HTML (`data-quire-event`). See docs/analytics.md.
- **One security policy, Quire's:** Quire sends the `Content-Security-Policy` of the site's pages, its sign-in pages (which never get other sites' hosts) and stored files. Other sites' hosts come only from quire/privacy's services, which visitors are asked about, and from the site's configuration (`quire.csp.sources`). A template that loads a service visitors are asked about directly stops the site from being built, and `quire:theme:lint` also warns about what the policy blocks and checks a theme's own cookie banner. See docs/security.md.
- **One head for every page:** `quire_head()` prints each head tag once, and extensions add theirs with `Extend\Head`.
- **The site's setup in the admin:** Site → Setup shows the site's address, whether it sends e-mails (with a test e-mail to yourself), and the security policy with every host it allows and why. Home warns when the policy only reports in production. "Look" shows what the checks find for every theme before it's used.

### Changed in a way that can break a site

- **Quire sends the security policy of the site's pages.** A site that sends its own (e.g. in its Caddyfile) gets both, and browsers apply both, so it should remove its own (see Upgrading).
- **Extensions' compiler passes run** (they never did before, see Fixed). A pass that was written but never ran now takes effect; passes after "before removing" are refused when the container is built.
- **`'strict-dynamic'` in `quire.csp.sources` is refused while quire/privacy is in use:** under it, browsers ignore the policy's hosts, which is what keeps services from loading before consent.
- New in 0.3 and not a change from 0.2: `Quire\Theme\ThemeCheckInterface` (with `rendersTheme()`), quire/privacy and quire/analytics. An extender that set Google Analytics' ID in `extend.php` existed only during 0.3's development and never shipped; the ID is a setting in the admin.

### Fixed

- An extension's compiler passes (`Extend\Services::compilerPass()`) never ran: extensions boot while the container is compiled. They run now, before Quire's own checks.

### Known limits

- **Google Analytics hasn't run against Google's real script yet.** quire/analytics is tested in browsers with a stand-in for gtag.js, which checks what Quire hands it (no tokens in addresses, nothing before consent, nothing after withdrawal); a run on a site with a real Google Analytics property and Google's own script, which shows what Google sends, is still to come. Until then, try it on a test property first.
- **The admin's own pages limit scripts less than the site's:** their security policy restricts frames, plugins and the base address, not where scripts come from (as in 0.2).
- **The German and Macedonian texts of quire/privacy and quire/analytics are drafts** until a native speaker checks them.

### Upgrading

A new minor version: sites require `^0.2`, so they move to it only when told, and there are no new migrations in Quire itself (quire/privacy has its own, run when it's enabled).
- Sites run by Quire Server: `quire-server site:update <site> --to=0.3.0` (server contract 2, unchanged). The server's nightly updates stay on 0.2.
- Other sites: `composer require "quire/core:^0.3" "quire/admin:^0.3" "quire/theme-default:^0.3"` (and `composer require --dev "quire/testing:^0.3"` where it's used). The skeleton's files didn't change but for a comment in `frankenphp/Caddyfile`.
- Extensions and themes: require `"quire/core": "^0.3"`.

What changes for sites, themes and extensions:
- A site that sends its own `Content-Security-Policy` for its pages (e.g. in its Caddyfile) should remove it: Quire sends the policy now, and browsers apply every policy a response has, so a second one silently blocks what Quire's allows. Add hosts to `quire.csp.sources` instead (docs/security.md).
- Themes: `quire_seo()` is now `quire_head()`; the old name works until 1.0, and `quire:theme:lint` says where it's used.
- Extensions whose `extend.php` adds a compiler pass (`Extend\Services::compilerPass()`): passes never ran before, and do now. Check what yours does; passes after "before removing" are refused.
- quire/privacy and quire/analytics are new packages: `composer require "quire/privacy:^0.3" "quire/analytics:^0.3"` when a site needs them, then enable them in the admin. Sites run by Quire Server can't add packages yet; that comes with a Quire Server release.

## [0.2.2](https://github.com/quirecms/framework/releases/tag/v0.2.2)

A site with a theme or an extension of its own builds for production, and the ones Quire makes install.

- **Themes and extensions in the site build for production:** the `Dockerfile` copies `themes/` and `extensions/` before Composer installs the packages from them, so a site with its own theme (`quire:make:theme`) or extension (`quire:make:extension`) no longer fails its production build, on Quire Server too. Sites without them build as before.
- **What the makers create installs:** the new theme's or extension's `composer.json` requires the Quire version the site runs (`^0.2`), and a test checks that the makers keep up with each new version.
- **Their tests run:** the makers add Quire's test kit (`quire/testing`, for development only) when the site doesn't have it, so `vendor/bin/phpunit -c themes/<name>` works in a new site.

### Upgrading

A patch release: sites on `^0.2` take it with their next update, with no new migrations. The skeleton's `Dockerfile` changed: sites run by Quire Server get it with the update unless they edited theirs (then the update shows the difference to merge); other sites can take it from https://github.com/quirecms/skeleton/blob/v0.2.2/Dockerfile.
- A theme or extension made with `quire:make:theme` or `quire:make:extension` on 0.2.0 or 0.2.1 requires `quire/core` `^0.1@dev`: change it to `^0.2` (and `quire/testing` the same) in its `composer.json`.

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
