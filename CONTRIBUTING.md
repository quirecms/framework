# Contributing to Quire

Thanks for helping to improve Quire! This guide explains how to set up the project, what we expect from changes, and how to get them merged.

Everyone taking part follows our [code of conduct](https://github.com/quirecms/.github/blob/main/CODE_OF_CONDUCT.md).

## Before you start

- **Security issues:** don't open a public issue. Follow the [security policy](SECURITY.md).
- **Bugs:** search the [existing issues](https://github.com/quirecms/framework/issues) first. If yours is new, open an issue with steps to reproduce, what you expected, and what happened instead.
- **New features and larger changes:** open an issue to discuss them before writing code. Quire keeps its core deliberately small, so many features are better built as extensions. Agreeing on the approach first saves you from writing code that can't be merged.
- **Small fixes** (typos, docs, obvious bugs) can go straight to a pull request.

## Setting up

You need Docker with Docker Compose v2.10 or later. PHP, Composer and PostgreSQL all run inside the containers.

```bash
git clone https://github.com/quirecms/framework.git quire
cd quire
docker compose up --wait
```

The site runs at <https://quire.localhost>. See the [README](README.md) for other ports and [docs/docker.md](docs/docker.md) for details of the Docker setup.

On Linux, run commands in the container as your own user so the files they create aren't owned by root:

```bash
docker compose exec -u "$(id -u):$(id -g)" -e HOME=/tmp php composer test
```

## Repository layout

This is a monorepo. Develop everything here, even code that's published as separate packages: each directory of `packages/` is copied to a read-only repository of its own (e.g. [quirecms/core](https://github.com/quirecms/core)), which Composer installs it from, and the starter project is built from the application into [quirecms/skeleton](https://github.com/quirecms/skeleton). Pull requests opened there are closed with a pointer to this repository.

| Path | Contents |
|------|----------|
| `packages/core/` | `quire/core`, the engine |
| `packages/…` | First-party extensions, themes and tools (more to come) |
| `skeleton/` | What only `quire/skeleton`, the starter project of new sites, has: its README, its `composer.json` |
| `config/`, `src/`, `templates/`, `public/`, `tests/` | The application used to run and test the packages, and that new sites start from |

The application installs the packages from `packages/` through Composer path repositories, so your changes apply immediately.

The starter project `quire/skeleton` is built from the application (`.github/skeleton.sh`): its files exist once, here, so a change to one of them, such as `config/packages/security.yaml` or the `Dockerfile`, reaches new sites by itself. What only Quire's own development needs stays out of it:

- files only the tests and tools need: `tests/`, `.env.test`, `config/packages/test/`, `config/services_dev.yaml` and `config/services_test.yaml` (listed in `.github/skeleton.sh`);
- lines of shared files between `# >>> development only` and `# <<< development only`, e.g. the S3 service of `compose.override.yaml`. A line `# skeleton: <line>` becomes `<line>` in the skeleton (e.g. its empty `APP_SECRET`).

Build it to see what new sites get: `.github/skeleton.sh /tmp/skeleton`. A test (`tests/Skeleton/SkeletonTest.php`) builds it and fails when a file of the application wouldn't reach new sites, or something of the development would. CI creates a site from it with the packages of your branch and checks that it runs; publishing commits it to quirecms/skeleton.

## Making changes

1. Fork the repository and create a branch from `main`, named `<kind>/<slug>` (see [below](#pull-requests-commits-and-branches)), e.g. `fix/health-check-timeout` or `feat/settings-extender`.
2. Make your change, with tests (see below).
3. Run all checks:

   ```bash
   docker compose exec php composer qa
   ```

   CI runs the same checks, and a pull request can only be merged when they pass. It also runs `composer audit` on the lock: a dependency with a known advisory fails the build. `composer fix` applies coding-standard fixes and automated refactorings for you.
4. Open a pull request against `main`.

### Code guidelines

- Every PHP file starts with `declare(strict_types=1);`.
- Classes are `final` unless they're designed to be extended.
- Use constructor injection. Don't use service locators or static facades.
- Code must pass PHPStan at its strictest level (`level: max`). Fix the underlying type problem instead of adding ignores or baseline entries.
- Mark classes and interfaces that extensions may rely on with `@api`. They're covered by semantic versioning, so changing them is a breaking change.
- Long-running workers reuse services across requests. Don't keep request-specific state in services, or implement `Symfony\Contracts\Service\ResetInterface`.
- Quire stores all data in PostgreSQL. Don't add file-based storage.

### Tests

- Fixes need a test that fails without the fix.
- New features need tests for their normal behaviour and their edge cases.
- Unit tests for a package live in that package's `tests/` folder (e.g. `packages/core/tests/`). Tests that need the whole application live in the root `tests/` folder.
- Run a single test with `composer test -- --filter NameOfTest`.
- Tests send no email: the test environment uses the null transport whatever `MAILER_DSN` says, and tests read the messages with `assertEmailCount()` and `getMailerMessage()`. They don't need the `mailer` service.
- Tests that compare times (an item's publication, a backup's) assume the machine's clock doesn't jump. On WSL2, when tests fail now and then for no reason, check that the clock isn't being stepped back (`journalctl | grep 'Clock change detected'`) and that Windows syncs its own time.
- Changes to the default theme's templates or CSS must keep it accessible: CI checks it with axe-core (see "Accessibility" in [docs/themes.md](docs/themes.md#accessibility) to run the same check locally).

## Before you open a pull request

A good pull request does one thing (unrelated changes go in separate pull requests), and passes CI. Before you open one, check that:

- tests cover the change, and a fix has a test that fails without it;
- `docker compose exec php composer qa` passes;
- behavior users see is documented in `docs/`, and in the next release's notes in `docs/releases/`;
- theme or admin templates and CSS still pass the accessibility check (axe-core), and approved screenshots are updated on purpose;
- `@api` changes follow semantic versioning (deprecate before removing);
- new or changed texts are translatable, with a translation in each language the package ships, and valid ICU.

Commits on a branch are free-form, since they're squashed when merging. Please push new commits instead of force-pushing, so the review is easier to follow, and add `Co-authored-by` only for a human co-author. A maintainer will review the pull request, and may ask for changes. Changes to how Quire is released (`.github/release-signers`, the workflows and the scripts they check a release with) need a code owner's review (`.github/CODEOWNERS`).

## Pull requests

Pull requests are squashed: the title becomes the commit's subject on `main`, followed by `(#N)`, and the description becomes its body. Write both for the change as a whole, never by copying the branch's commit messages.

**The title** is `<Verb> <what changes>`:
- It starts with an imperative verb, capitalized, e.g. Add, Fix, Refuse, Let, Make, Move, Remove, Rename, Read, Run, Show, Keep.
- It names one change, as whoever uses it sees it. No colon, no semicolon and no list of several changes: if it needs a list, the title is the umbrella and the list goes under "What changes".
- At most 72 characters, with no trailing period, no `feat:`-style prefix, no product name and no version. Only Dependabot's `chore:` stays, so dependency updates can be filtered.
- A release's pull request is titled exactly `Release X.Y.Z` (or `Release X.Y.Z-rc.N`), and the one that moves `main` on to the next minor exactly `Start X.Y`.

Good: "Refuse a release whose tag isn't signed". Not: "feat: release tags are checked", "Releases: signed tags, checksums and docs".

**The description** has exactly these sections, in this order, and nothing before, between or after them (the template has them):
- `## Why`: one to three sentences about the problem, for whoever uses it.
- `## What changes`: one behavior per bullet. A breaking change is the first bullet, starting "Breaking:".
- `## Tested`: what ran, then `NOT RUN: <what>, because <why>` for anything that didn't.
- `## Refs`: public links only (issues, pull requests, published advisories, RFCs, upstream docs). Leave the section out when there are none.

A release's pull request uses the same sections: "Why" is `Release X.Y.Z.`, and "What changes" has one bullet per changelog heading.

**Nothing internal in public text.** Titles, descriptions, commit messages, branch names, tag messages, release notes and the changelog say what the code changes and why, for a reader outside the project. They carry no internal tracking codes or decision numbers, no names of who decided, reviewed or approved what, and no paths outside the repository.

**Before you send it, check:**
1. Does the title start with an imperative verb?
2. Does the title name one change, with no colon or list?
3. Is it 72 characters or fewer, with no prefix and no trailing period?
4. Does the description have exactly Why, What changes, Tested and (when there are any) Refs, in that order, and nothing else?
5. Is there nothing internal in either?

If the branch gains commits after the pull request is opened, update the title and description so they still describe the whole change.

**Branches** are `<kind>/<slug>`, where the kind is one of `feat`, `fix`, `docs`, `chore`, `ci` or `security`, and the slug is lowercase with hyphens and describes the change, with no internal code. A release is prepared on `chore/release-X.Y.Z`. `release/X.Y` is reserved for maintenance branches.

**A fix of an embargoed vulnerability** is developed in the repository's private security advisory fork, never on a public branch. Until the advisory is published, its title, description and branch describe the change neutrally: no advisory ID, and no description of the exploit. The changelog's Security entry and the advisory ID are added at the release.

## Releases

- **Tags and release branches:** a release tag is `vX.Y.Z`, or `vX.Y.Z-rc.N` for a release candidate, with no other labels; annotated, SSH-signed by a key in `.github/release-signers`, with the message `Quire X.Y.Z`, on a commit of `main` that passed CI. The Release and Split workflows refuse any other tag, before anything that can publish runs. Releases come from `main`; `release/X.Y` is created from `vX.Y.0` only when an older minor needs a fix after `main` has moved on (fixed on `main` first, then `git cherry-pick -x`).
- **What a release publishes:** a GitHub release titled `Quire X.Y.Z` (a pre-release for `-rc.N`), with the release's notes; `CHANGELOG.md` in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format, with `## [X.Y.Z] - YYYY-MM-DD` headings; and its release feed, signed with Sigstore. Before 1.0, a minor release may break things and a patch only fixes them; a release's "Upgrading" says what someone running a site must do. A release candidate's skeleton requires Quire's packages from that candidate on (`^X.Y.Z-rc.N`), so a new site can install it.
- Report a vulnerability as [SECURITY.md](SECURITY.md) says.

All packages are released together, with the same version, from a tag of this repository: tagging a commit of `main` with `v0.1.0` gives that version to every package. Tag with `git tag -s v0.1.0 -m "Quire 0.1.0"`, signed by a key in `.github/release-signers` as `main` has it (`.github/verify-tag.sh v0.1.0` checks a tag the way the workflows do). The workflow "Split" copies `main` and each tag to the packages' repositories (`.github/split.json` lists them; `.github/split.sh main` shows what it would push, without pushing). Before tagging, check that Packagist has every package Split pushes, from the repository Split pushes it to: `docker compose exec php php .github/packagist.php` (a new package, like `quire/analytics` in 0.3.0, is submitted at https://packagist.org/packages/submit once its repository has `main`). The release workflow checks it first, and publishes nothing when it fails. A tag publishes nothing until CI has passed on `main` for its commit: both workflows wait for it (`.github/ci-passed.sh`, 45 minutes at most) and stop if it failed. Then re-run CI's run for that commit ("Run workflow" by hand doesn't count), and the two workflows once it has passed.

Every release has its notes in `docs/releases/<version>.md`, written before it's tagged (the release fails without them). They start with a header that tools read:

```markdown
---
date: 2026-11-19               # the day it's released: CHANGELOG.md's heading
security:                      # the advisories this release fixes; [] for none
  - id: GHSA-2c3f-4g5h-6j7m    # or a CVE
    severity: high             # low, moderate, high or critical
    package: quire/core
    summary: A draft could be read by its link.
upgrade: |                     # what to do when updating to it; null for nothing
  Run `bin/console quire:…` after updating.
---
What changed, for the people who run sites.
```

The workflow "Release" then publishes the GitHub release with them, and Quire's release feed, `feed.json`: every release up to this one, with its notes, security fixes, upgrade notes, requirements, the skeleton's files and each package's commit in the repository Composer installs it from, signed with Sigstore by that workflow for that tag. Quire Server reads it to know what to update, and which updates are urgent, and refuses to build a site whose `quire/*` packages aren't those commits, or whose new skeleton isn't those files: a tag moved in a package's repository, or other code served for it, is caught. The release computes the commits itself, with `.github/split.sh <tag>` (the split gives the same commits for the same history); releases from before the feed had them are given their tags' commits as they are when a release is made. Every workflow's actions are pinned by commit (Dependabot proposes updates). `php .github/feed.php --version=0.1.2 --release-notes` shows a release's text; the tests check every notes file. `CHANGELOG.md` is every release's text, made from these files: after writing or changing one, run `php .github/feed.php --changelog > CHANGELOG.md` (a test fails until it's up to date).

The runtime images sites build on (`runtime/Dockerfile`, see [docs/docker.md](docs/docker.md#the-runtime-images)) aren't tied to releases: the workflow "Runtime" builds, signs and publishes them every week, whenever `runtime/` changes on `main`, and by hand. CI builds the site's images on the runtime of the commit it tests (`.github/runtime.hcl`), so a change there is tested before it's published. A new PHP version gets its own tags (`8.6`, `8.6-builder`) next to the current ones.

Between releases, the packages develop the next version, `0.5.x-dev`: their `main` branches are aliased to it, and they require each other in it (`^0.5`). To start the next minor version, change both in every `composer.json`, and the versions of the path repository in the root one; a test checks that they agree. A new package goes into `.github/split.json`, with its repository, and gets the `LICENSE`, `.gitattributes` and `.github/workflows/close-pull-request.yaml` the others have.

## License

By contributing, you agree that your contributions are licensed under the [MIT license](LICENSE), the same license as the project.
